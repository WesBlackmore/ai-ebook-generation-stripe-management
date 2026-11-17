<?php

declare(strict_types=1);

/** Refunds go through Stripe, then the local transaction row. Never negative, never more than was paid. */

const REFUNDABLE_STATES = ['succeeded', 'paid', 'partially_refunded'];

/**
 * Raise a transaction's refunded total to `$totalRefundedCents` (an absolute figure, so a repeat or
 * a redelivery is a no-op). The guard lives in the UPDATE: the total may only grow and never exceed
 * the amount paid. Returns true only when this call changed the row.
 */
function transaction_apply_refund(string $intentId, int $totalRefundedCents): bool
{
    $statement = stripe_db()->prepare("UPDATE transactions
        SET refunded_cents = :total,
            status = CASE WHEN :total >= amount_cents THEN 'refunded' ELSE 'partially_refunded' END
        WHERE stripe_intent_id = :id AND :total > refunded_cents AND :total <= amount_cents
          AND status IN ('succeeded', 'paid', 'partially_refunded')");
    $statement->execute(['total' => $totalRefundedCents, 'id' => $intentId]);

    return $statement->rowCount() === 1;
}

/** Resolve the PaymentIntent a transaction row refers to. Subscription rows are keyed on the invoice id. */
function refund_payment_intent_id(string $recordedId): string
{
    if (str_starts_with($recordedId, 'pi_')) return $recordedId;
    if (preg_match('/^in_[A-Za-z0-9_]+$/', $recordedId) !== 1) throw new InvalidArgumentException('Unknown payment id.');
    /* Basil: the invoice payment record links the invoice to its PaymentIntent. */
    $found = stripe_api('GET', 'invoice_payments', ['invoice' => $recordedId, 'limit' => 1]);
    $intent = $found['data'][0]['payment']['payment_intent'] ?? null;
    if (is_array($intent)) $intent = $intent['id'] ?? null;
    if (!is_string($intent) || $intent === '') throw new RuntimeException('Could not find the payment behind that invoice.');

    return $intent;
}

/**
 * Refund a recorded payment (a PaymentIntent id, or an invoice id for subscription payments).
 * `$amountCents` null refunds whatever remains. Needs a live call.
 *
 * @return array{ok: bool, error?: string, refunded_cents?: int, status?: string, refund?: array}
 */
function refund_transaction(string $recordedId, ?int $amountCents = null, ?string $reason = null): array
{
    if (!stripe_configured()) return stripe_not_configured();
    $record = transaction_by_intent($recordedId);
    if ($record === null) return ['ok' => false, 'error' => 'No such payment.'];
    if (!in_array((string) $record['status'], REFUNDABLE_STATES, true)) {
        return ['ok' => false, 'error' => 'That payment is ' . $record['status'] . ' and cannot be refunded.'];
    }
    $paid = (int) $record['amount_cents'];
    $already = (int) $record['refunded_cents'];
    $remaining = $paid - $already;
    $amount = $amountCents ?? $remaining;
    if ($amount < 1 || $amount > $remaining) {
        return ['ok' => false, 'error' => 'The refund must be between 1 and ' . $remaining . ' cents.'];
    }
    try {
        $params = ['payment_intent' => refund_payment_intent_id($recordedId), 'amount' => $amount,
            'metadata' => ['account_id' => (string) $record['account_id']]];
        if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) $params['reason'] = $reason;
        /* Keyed on what was already refunded, so a retry dedupes and a later partial refund is new. */
        $refund = stripe_api('POST', 'refunds', $params, 'refund:' . $recordedId . ':' . $already . ':' . $amount);
    } catch (Throwable $exception) {
        stripe_log('refund failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Stripe did not accept the refund. Nothing changed.'];
    }
    try {
        transaction_apply_refund($recordedId, $already + $amount);
    } catch (Throwable $exception) {
        /* The money is refunded at Stripe; the charge.refunded webhook brings the row in line. */
        stripe_log('refund local update failed: ' . substr($exception->getMessage(), 0, 200));
    }
    $after = transaction_by_intent($recordedId) ?? $record;

    return ['ok' => true, 'refunded_cents' => (int) $after['refunded_cents'], 'status' => (string) $after['status'],
        'refund' => $refund];
}

/** The invoice id behind a refunded charge, or null for a one-time payment. */
function refund_invoice_id(array $charge): ?string
{
    $invoice = $charge['invoice'] ?? null;
    if (is_array($invoice)) $invoice = $invoice['id'] ?? null;
    if (is_string($invoice) && preg_match('/^in_[A-Za-z0-9_]+$/', $invoice) === 1) return $invoice;

    return null;
}

/**
 * Bring the local row in line with a charge.refunded event: the charge's own amount_refunded is
 * the truth, applied as an absolute total. Returns true only when a row changed.
 */
function refund_apply_charge(array $charge): bool
{
    $total = (int) ($charge['amount_refunded'] ?? 0);
    if ($total < 1) return false;
    $candidates = [];
    $intentId = (string) ($charge['payment_intent'] ?? '');
    if ($intentId !== '') $candidates[] = $intentId;
    $invoiceId = refund_invoice_id($charge);
    if ($invoiceId !== null) $candidates[] = $invoiceId;
    foreach ($candidates as $id) {
        if (transaction_by_intent($id) !== null) return transaction_apply_refund($id, $total);
    }
    stripe_log('charge.refunded for an unknown payment ' . ($intentId !== '' ? $intentId : '(no intent)'));

    return false;
}
