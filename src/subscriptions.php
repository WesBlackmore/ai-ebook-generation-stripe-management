<?php

declare(strict_types=1);

/**
 * Subscription lifecycle: checkout, state sync, cancel at period end, undo, immediate cancel, plan
 * change and the customer portal. State lives in stripe_customers, keyed by the host's account id.
 * Every function here that writes to Stripe returns a result array and never throws.
 */

const SUBSCRIPTION_CANCEL_REASONS = ['too_expensive', 'unused', 'missing_features', 'low_quality', 'other'];

function subscription_is_active(array $account): bool
{
    return in_array((string) ($account['subscription_status'] ?? ''), ['active', 'trialing'], true);
}

/**
 * When the paid period ends. Basil moved `current_period_end` off the subscription and onto each
 * item, so both shapes are read and the latest wins.
 */
function subscription_period_end(array $subscription): int
{
    $end = (int) ($subscription['current_period_end'] ?? 0);
    foreach ($subscription['items']['data'] ?? [] as $item) {
        $end = max($end, (int) ($item['current_period_end'] ?? 0));
    }

    return $end;
}

/** The subscription id an invoice belongs to (Basil nested shape first, then the legacy field). */
function invoice_subscription_id(array $invoice): string
{
    $nested = $invoice['parent']['subscription_details']['subscription'] ?? null;
    if (is_array($nested)) $nested = $nested['id'] ?? null;
    if (is_string($nested) && $nested !== '') return $nested;
    $legacy = $invoice['subscription'] ?? null;
    if (is_array($legacy)) $legacy = $legacy['id'] ?? null;

    return is_string($legacy) ? $legacy : '';
}

/**
 * The ordering key for a Stripe event: padded seconds so a byte-wise comparison orders by time,
 * then the event id as a tiebreak. Null when no event is carried.
 */
function subscription_event_seq(?array $event): ?string
{
    if ($event === null) return null;
    $created = (int) ($event['created'] ?? 0);
    $id = (string) ($event['id'] ?? '');

    return $created > 0 && $id !== '' ? sprintf('%010d:%s', $created, $id) : null;
}

/** The account a subscription belongs to: metadata first, then the ids we already hold. */
function subscription_account_id(array $subscription): string
{
    $fromMeta = trim((string) ($subscription['metadata']['account_id'] ?? ''));
    if ($fromMeta !== '') return $fromMeta;
    $customer = $subscription['customer'] ?? '';
    if (is_array($customer)) $customer = $customer['id'] ?? '';
    $lookup = stripe_db()->prepare('SELECT account_id FROM stripe_customers
        WHERE (subscription_id = :s AND :s <> \'\') OR (stripe_customer_id = :c AND :c <> \'\') LIMIT 1');
    $lookup->execute(['s' => (string) ($subscription['id'] ?? ''), 'c' => (string) $customer]);

    return (string) ($lookup->fetchColumn() ?: '');
}

/**
 * Record what Stripe says about a subscription.
 *
 * Called from the webhook with the event, and by the package's own writes with `$authoritative`
 * (the response to a change we just made). Only a strictly newer event may overwrite the same
 * subscription, so redelivery, reversed pairs and same-second deliveries resolve deterministically.
 * A different subscription id may replace the held one only when it is active or trialing, and an
 * unpaid incomplete one never demotes a live plan.
 *
 * @return bool true when the row was written
 */
function apply_subscription_state(array $subscription, ?array $event = null, bool $authoritative = false): bool
{
    $subscriptionId = (string) ($subscription['id'] ?? '');
    $accountId = subscription_account_id($subscription);
    if ($accountId === '' || $subscriptionId === '') return false;
    $status = (string) ($subscription['status'] ?? '');
    $incomingSeq = $authoritative ? sprintf('%010d:~local', time()) : subscription_event_seq($event);

    $held = stripe_account($accountId);
    $heldId = trim((string) ($held['subscription_id'] ?? ''));
    $heldSeq = $held['subscription_event_seq'] ?? null;
    $heldLive = subscription_is_active($held);

    if ($heldId !== '' && $heldId === $subscriptionId) {
        /* A call without an event never overwrites a row an event has already stamped. */
        if ($incomingSeq === null && $heldSeq !== null) return false;
        if (!$authoritative && $incomingSeq !== null && $heldSeq !== null && strcmp($incomingSeq, (string) $heldSeq) <= 0) return false;
    } else {
        if (in_array($status, ['incomplete', 'incomplete_expired'], true)) {
            if ($heldLive) {
                stripe_log('refused to demote live subscription ' . $heldId . ' for ' . $accountId);
                return false;
            }
        } elseif (!in_array($status, ['active', 'trialing'], true)) {
            return false; /* canceled/past_due/unpaid on an id we do not hold: an orphan event. */
        }
        if ($incomingSeq === null && $heldId !== '' && $heldLive && $heldSeq !== null) return false;
    }

    $plan = trim((string) ($subscription['metadata']['plan_key'] ?? ''));
    if ($plan === '') $plan = (string) ($subscription['items']['data'][0]['price']['id'] ?? '');
    if ($plan === '' && $heldId === $subscriptionId) $plan = (string) ($held['subscription_plan'] ?? '');
    $end = subscription_period_end($subscription);
    $customer = $subscription['customer'] ?? '';
    if (is_array($customer)) $customer = $customer['id'] ?? '';
    $columns = [
        'subscription_id' => $subscriptionId,
        'subscription_status' => $status,
        'subscription_plan' => $plan,
        'subscription_period_end' => $end > 0 ? gmdate('Y-m-d H:i:s', $end) : null,
        'cancel_at_period_end' => (!empty($subscription['cancel_at_period_end']) || !empty($subscription['cancel_at'])) ? 1 : 0,
        'subscription_event_seq' => $incomingSeq,
    ];
    if (is_string($customer) && $customer !== '') $columns['stripe_customer_id'] = $customer;
    stripe_account_set($accountId, []);
    /* Identity check in the write itself: another writer that swapped the id meanwhile wins. */
    $sets = [];
    foreach (array_keys($columns) as $name) $sets[] = $name . ' = :' . $name;
    $statement = stripe_db()->prepare('UPDATE stripe_customers SET ' . implode(', ', $sets)
        . ', updated_at = CURRENT_TIMESTAMP WHERE account_id = :account_id AND COALESCE(subscription_id, \'\') = :expected');
    $statement->execute($columns + ['account_id' => $accountId, 'expected' => $heldId]);

    return $statement->rowCount() === 1;
}

/**
 * Start a subscription through hosted Checkout. Returns ['ok' => true, 'url' => ...] to redirect
 * the browser to. Refused when the account already has a live plan (use change_plan instead).
 *
 * Options: email, success_url, cancel_url, idempotency_key, plan_key, metadata, quantity.
 */
function begin_subscription_checkout(string $accountId, string $priceId, array $opts = []): array
{
    if (!stripe_configured()) return stripe_not_configured();
    if ($accountId === '' || preg_match('/^price_[A-Za-z0-9_]+$/', $priceId) !== 1) {
        return ['ok' => false, 'error' => 'An account and a Stripe price id are required.'];
    }
    if (subscription_is_active(stripe_account($accountId))) {
        return ['ok' => false, 'error' => 'This account already has a plan.'];
    }
    try {
        $customerId = stripe_customer_for($accountId, (string) ($opts['email'] ?? ''));
        $metadata = (array) ($opts['metadata'] ?? []);
        if (!empty($opts['plan_key'])) $metadata['plan_key'] = (string) $opts['plan_key'];
        $params = checkout_session_params('subscription', $accountId,
            [['price' => $priceId, 'quantity' => max(1, (int) ($opts['quantity'] ?? 1))]],
            $opts['success_url'] ?? null, $opts['cancel_url'] ?? null, ['metadata' => $metadata, 'customer' => $customerId]);
        $session = stripe_api('POST', 'checkout/sessions', $params,
            (string) ($opts['idempotency_key'] ?? 'subscription-checkout:' . bin2hex(random_bytes(16))));

        return ['ok' => true, 'url' => stripe_checkout_url($session), 'session' => $session];
    } catch (Throwable $exception) {
        stripe_log('begin_subscription_checkout failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Secure checkout could not be opened.'];
    }
}

/** Pull the live subscription from Stripe into the local row. Needs a live call. */
function subscription_refresh(string $accountId): array
{
    if (!stripe_configured()) return stripe_not_configured();
    $id = trim((string) (stripe_account($accountId)['subscription_id'] ?? ''));
    if ($id === '') return ['ok' => false, 'error' => 'There is no plan to refresh.'];
    try {
        $subscription = stripe_api('GET', 'subscriptions/' . rawurlencode($id));
        apply_subscription_state($subscription, null, true);

        return ['ok' => true, 'subscription' => $subscription];
    } catch (Throwable $exception) {
        stripe_log('subscription_refresh failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Stripe did not answer.'];
    }
}

/** True when the account has a plan we can still cancel or resume. */
function subscription_is_manageable(array $account): bool
{
    return trim((string) ($account['subscription_id'] ?? '')) !== ''
        && in_array((string) ($account['subscription_status'] ?? ''), ['active', 'trialing', 'past_due'], true);
}

/**
 * Cancel at the end of the paid period, or undo that. Never immediate and never a refund: the
 * period was paid for. A cancel returns a single-use `undo_token` (valid 24 hours) that the host
 * puts in its goodbye email; subscription_undo_cancel() redeems it.
 *
 * @return array{ok: bool, error?: string, ends_at?: int, undo_token?: string}
 */
function subscription_set_cancel(string $accountId, bool $cancel, string $reason = '', string $comment = ''): array
{
    if (!stripe_configured()) return stripe_not_configured();
    $account = stripe_account($accountId);
    if (!subscription_is_manageable($account)) return ['ok' => false, 'error' => 'There is no active plan to change.'];
    $id = (string) $account['subscription_id'];
    $params = ['cancel_at_period_end' => $cancel ? 'true' : 'false'];
    if ($cancel) {
        $comment = trim(mb_substr(trim($comment), 0, 500));
        if (in_array($reason, SUBSCRIPTION_CANCEL_REASONS, true)) $params['cancellation_details']['feedback'] = $reason;
        if ($comment !== '') $params['cancellation_details']['comment'] = $comment;
    }
    try {
        /* Keyed on the exact params, not the clock, so a retry dedupes to the first call's response. */
        $subscription = stripe_api('POST', 'subscriptions/' . rawurlencode($id), $params,
            'plan-state:' . $id . ':' . hash('sha256', serialize($params)));
    } catch (Throwable $exception) {
        stripe_log('plan ' . ($cancel ? 'cancel' : 'resume') . ' failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Stripe did not answer, so nothing changed. Please try again.'];
    }
    try {
        apply_subscription_state($subscription, null, true);
    } catch (Throwable $exception) {
        /* Stripe already changed; the webhook delivers the same state. */
        stripe_log('set_cancel local sync failed: ' . substr($exception->getMessage(), 0, 200));
    }
    $result = ['ok' => true, 'ends_at' => (int) ($subscription['cancel_at'] ?? 0) ?: subscription_period_end($subscription)];
    try {
        if ($cancel) $result['undo_token'] = subscription_undo_issue($accountId);
        else subscription_undo_clear($accountId);
    } catch (Throwable $exception) {
        stripe_log('undo token bookkeeping failed: ' . substr($exception->getMessage(), 0, 200));
    }

    return $result;
}

/** Issue a fresh single-use undo token (replaces any earlier one). Only its hash is stored. */
function subscription_undo_issue(string $accountId, int $ttlSeconds = 86400): string
{
    $token = bin2hex(random_bytes(24));
    stripe_account_set($accountId, ['undo_token_hash' => hash('sha256', $token),
        'undo_expires_at' => time() + max(60, $ttlSeconds)]);

    return $token;
}

function subscription_undo_clear(string $accountId): void
{
    stripe_account_set($accountId, ['undo_token_hash' => null, 'undo_expires_at' => null]);
}

/**
 * Redeem an undo token: resume the plan. The token is burned atomically before Stripe is called,
 * so a second use finds nothing; if Stripe fails, the token is re-armed with its original expiry.
 *
 * @return array{ok: bool, error?: string, account_id?: string}
 */
function subscription_undo_cancel(string $token): array
{
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) return ['ok' => false, 'error' => 'This link is not valid.'];
    $hash = hash('sha256', $token);
    $pdo = stripe_db();
    $row = $pdo->prepare('SELECT account_id, undo_expires_at FROM stripe_customers WHERE undo_token_hash = ?');
    $row->execute([$hash]);
    $held = $row->fetch();
    if (!$held || (int) $held['undo_expires_at'] < time()) return ['ok' => false, 'error' => 'This link has expired or was already used.'];
    $burn = $pdo->prepare('UPDATE stripe_customers SET undo_token_hash = NULL, undo_expires_at = NULL
        WHERE undo_token_hash = ? AND undo_expires_at >= ?');
    $burn->execute([$hash, time()]);
    if ($burn->rowCount() !== 1) return ['ok' => false, 'error' => 'This link has expired or was already used.'];

    $accountId = (string) $held['account_id'];
    $result = subscription_set_cancel($accountId, false);
    if (!$result['ok']) {
        $pdo->prepare('UPDATE stripe_customers SET undo_token_hash = ?, undo_expires_at = ? WHERE account_id = ?
            AND undo_token_hash IS NULL')->execute([$hash, (int) $held['undo_expires_at'], $accountId]);

        return ['ok' => false, 'error' => $result['error'] ?? 'The plan could not be resumed.'];
    }

    return ['ok' => true, 'account_id' => $accountId];
}

/**
 * End the plan now. Separate and explicit: it takes `$confirm = true` so it cannot be reached by
 * passing the wrong boolean to subscription_set_cancel(). No refund is issued.
 */
function subscription_cancel_immediately(string $accountId, bool $confirm = false): array
{
    if (!stripe_configured()) return stripe_not_configured();
    if ($confirm !== true) return ['ok' => false, 'error' => 'Immediate cancellation must be confirmed explicitly.'];
    $account = stripe_account($accountId);
    if (!subscription_is_manageable($account)) return ['ok' => false, 'error' => 'There is no active plan to cancel.'];
    $id = (string) $account['subscription_id'];
    try {
        $subscription = stripe_api('DELETE', 'subscriptions/' . rawurlencode($id), [], 'plan-end:' . $id);
        apply_subscription_state($subscription, null, true);
        subscription_undo_clear($accountId);

        return ['ok' => true, 'subscription' => $subscription];
    } catch (Throwable $exception) {
        stripe_log('immediate cancel failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Stripe did not answer, so nothing changed.'];
    }
}

/**
 * Move a live plan to another price, prorated and charged now. A declined card leaves the plan
 * exactly as it was (`error_if_incomplete`). Needs a live call.
 */
function subscription_change_plan(string $accountId, string $newPriceId, ?int $prorationDate = null, array $metadata = []): array
{
    if (!stripe_configured()) return stripe_not_configured();
    $account = stripe_account($accountId);
    if (!subscription_is_active($account) || preg_match('/^price_[A-Za-z0-9_]+$/', $newPriceId) !== 1) {
        return ['ok' => false, 'error' => 'There is no active plan to change, or the price is invalid.'];
    }
    $id = (string) $account['subscription_id'];
    $prorationDate ??= time();
    try {
        $current = stripe_api('GET', 'subscriptions/' . rawurlencode($id));
        $itemId = (string) ($current['items']['data'][0]['id'] ?? '');
        if ($itemId === '') throw new RuntimeException('The subscription has no item to change.');
        $params = [
            'items' => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior' => 'always_invoice',
            'proration_date' => $prorationDate,
            'payment_behavior' => 'error_if_incomplete',
            'metadata' => array_map('strval', $metadata) + ['account_id' => $accountId],
        ];
        $subscription = stripe_api('POST', 'subscriptions/' . rawurlencode($id), $params,
            'plan-change:' . $id . ':' . $newPriceId . ':' . $prorationDate);
    } catch (Throwable $exception) {
        stripe_log('change_plan failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'The plan could not be changed. Nothing was charged.'];
    }
    try {
        apply_subscription_state($subscription, null, true);
    } catch (Throwable $exception) {
        stripe_log('change_plan local sync failed: ' . substr($exception->getMessage(), 0, 200));
    }

    return ['ok' => true, 'subscription' => $subscription];
}

/**
 * A link to Stripe's own customer portal, where cards and plans are managed. The return URL comes
 * from the argument or APP_URL. Returns null when the account has no customer or Stripe fails.
 */
function subscription_portal_url(string $accountId, ?string $returnUrl = null): ?string
{
    $customerId = trim((string) (stripe_account($accountId)['stripe_customer_id'] ?? ''));
    if ($customerId === '' || !stripe_configured()) return null;
    try {
        $session = stripe_api('POST', 'billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => stripe_app_url($returnUrl ?? '/billing'),
        ]);
        $url = (string) ($session['url'] ?? '');

        return $url !== '' ? $url : null;
    } catch (Throwable $exception) {
        stripe_log('portal session failed: ' . substr($exception->getMessage(), 0, 200));

        return null;
    }
}
