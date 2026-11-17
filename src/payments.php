<?php

declare(strict_types=1);

/** Customers, one-time PaymentIntents, Checkout Sessions and off-session saved-card charges. */

/** The Stripe customer for an account, created on first use and remembered. Throws on Stripe failure. */
function stripe_customer_for(string $accountId, string $email = ''): string
{
    $existing = trim((string) (stripe_account($accountId)['stripe_customer_id'] ?? ''));
    if ($existing !== '') return $existing;
    $params = ['metadata' => ['account_id' => $accountId]];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $params['email'] = $email;
    /* The key covers the parameters: Stripe rejects a key reused with different arguments. */
    $customer = stripe_api('POST', 'customers', $params,
        'customer:' . $accountId . ':' . substr(sha1(json_encode($params) ?: ''), 0, 16));
    $id = (string) ($customer['id'] ?? '');
    if ($id === '') throw new RuntimeException('Stripe did not return a customer.');
    stripe_account_set($accountId, ['stripe_customer_id' => $id]);

    return $id;
}

/** The exact params create_payment_intent() sends. Pure, so it can be shown without a key. */
function payment_intent_params(string $accountId, int $amountCents, array $opts = []): array
{
    $params = [
        'amount' => $amountCents,
        'currency' => strtolower((string) ($opts['currency'] ?? stripe_currency())),
        'payment_method_types' => ['card'],
    ];
    if (!empty($opts['description'])) $params['description'] = (string) $opts['description'];
    if (!empty($opts['receipt_email']) && filter_var($opts['receipt_email'], FILTER_VALIDATE_EMAIL)) {
        $params['receipt_email'] = (string) $opts['receipt_email'];
    }
    if (!empty($opts['customer'])) $params['customer'] = (string) $opts['customer'];
    /* account_id is last so host metadata can never override the owner of the payment. */
    $params['metadata'] = array_map('strval', (array) ($opts['metadata'] ?? []));
    $params['metadata']['account_id'] = $accountId;

    return $params;
}

/**
 * Create a one-time PaymentIntent and record a pending transaction.
 *
 * The idempotency key is required: Stripe collapses a retried request onto the first one, but only
 * when the parameters are identical, so reuse the same key with the same arguments on a retry.
 *
 * @return array{ok: bool, error?: string, intent?: array, client_secret?: string, transaction_id?: int|null}
 */
function create_payment_intent(string $accountId, int $amountCents, string $idempotencyKey, array $opts = []): array
{
    if (!stripe_configured()) return stripe_not_configured();
    if ($accountId === '' || $amountCents < 1 || $idempotencyKey === '') {
        return ['ok' => false, 'error' => 'An account, a positive amount and an idempotency key are required.'];
    }
    $params = payment_intent_params($accountId, $amountCents, $opts);
    if (preg_match('/^[a-z]{3}$/', $params['currency']) !== 1) return ['ok' => false, 'error' => 'Invalid currency.'];
    try {
        $intent = stripe_api('POST', 'payment_intents', $params, $idempotencyKey);
        $intentId = (string) ($intent['id'] ?? '');
        $transactionId = null;
        if ($intentId !== '') {
            /* A replay of the same intent returns null from transaction_record(): already recorded. */
            $transactionId = transaction_record($accountId, (string) ($opts['kind'] ?? 'payment'),
                $amountCents, $params['currency'], $intentId);
        }

        return ['ok' => true, 'intent' => $intent, 'client_secret' => (string) ($intent['client_secret'] ?? ''),
            'transaction_id' => $transactionId];
    } catch (Throwable $exception) {
        stripe_log('create_payment_intent failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'The payment could not be set up.'];
    }
}

function retrieve_payment_intent(string $id, bool $expandCharge = false): array
{
    if (preg_match('/^pi_[A-Za-z0-9_]+$/', $id) !== 1) throw new InvalidArgumentException('Invalid Stripe payment.');

    return stripe_api('GET', 'payment_intents/' . rawurlencode($id),
        $expandCharge ? ['expand' => ['latest_charge']] : []);
}

function retrieve_charge(string $id): array
{
    if (preg_match('/^ch_[A-Za-z0-9_]+$/', $id) !== 1) throw new InvalidArgumentException('Invalid Stripe charge.');

    return stripe_api('GET', 'charges/' . rawurlencode($id));
}

/** Stripe's hosted receipt for a succeeded payment, or null. Needs a live call. */
function payment_receipt_url(string $intentId): ?string
{
    try {
        $intent = retrieve_payment_intent($intentId, true);
        if (($intent['status'] ?? '') !== 'succeeded') return null;
        $charge = $intent['latest_charge'] ?? null;
        if (is_string($charge)) $charge = retrieve_charge($charge);

        return is_array($charge) ? stripe_hosted_receipt_url($charge) : null;
    } catch (Throwable $exception) {
        stripe_log('receipt lookup failed: ' . substr($exception->getMessage(), 0, 200));

        return null;
    }
}

/**
 * The exact Checkout Session params for a payment or subscription. Pure, so it can be printed
 * without a key. Return URLs come from the arguments, else from APP_URL.
 *
 * @param list<array{price?: string, quantity?: int, price_data?: array}> $lineItems
 */
function checkout_session_params(string $mode, string $accountId, array $lineItems, ?string $successUrl = null,
    ?string $cancelUrl = null, array $extra = []): array
{
    $success = $successUrl ?? stripe_app_url('/checkout/success?session_id={CHECKOUT_SESSION_ID}');
    $params = [
        'mode' => $mode,
        'client_reference_id' => $accountId,
        'line_items' => $lineItems,
        'success_url' => stripe_app_url($success),
        'cancel_url' => stripe_app_url($cancelUrl ?? '/checkout/cancel'),
    ];
    /* The account travels in metadata on the session and on the object it creates, so the webhook
       can find the owner whichever object it arrives holding. */
    $metadata = array_map('strval', (array) ($extra['metadata'] ?? []));
    $metadata['account_id'] = $accountId;
    unset($extra['metadata']);
    $params['metadata'] = $metadata;
    if ($mode === 'subscription') $params['subscription_data'] = ['metadata' => $metadata];
    if ($mode === 'payment') $params['payment_intent_data'] = ['metadata' => $metadata];

    return $params + $extra;
}

/**
 * Create a hosted Checkout Session for a one-time payment (price ids or price_data line items).
 *
 * @return array{ok: bool, error?: string, url?: string, session?: array}
 */
function create_payment_checkout(string $accountId, array $lineItems, ?string $successUrl = null,
    ?string $cancelUrl = null, ?string $idempotencyKey = null, array $extra = []): array
{
    return create_checkout_session('payment', $accountId, $lineItems, $successUrl, $cancelUrl, $idempotencyKey, $extra);
}

function create_checkout_session(string $mode, string $accountId, array $lineItems, ?string $successUrl = null,
    ?string $cancelUrl = null, ?string $idempotencyKey = null, array $extra = []): array
{
    if (!stripe_configured()) return stripe_not_configured();
    if ($accountId === '' || !$lineItems) return ['ok' => false, 'error' => 'An account and at least one line item are required.'];
    try {
        $params = checkout_session_params($mode, $accountId, $lineItems, $successUrl, $cancelUrl, $extra);
        $session = stripe_api('POST', 'checkout/sessions', $params,
            $idempotencyKey ?? 'checkout:' . $mode . ':' . bin2hex(random_bytes(16)));

        return ['ok' => true, 'url' => stripe_checkout_url($session), 'session' => $session];
    } catch (Throwable $exception) {
        stripe_log('create_checkout_session failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'Secure checkout could not be opened.'];
    }
}

function retrieve_checkout_session(string $id): array
{
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $id) !== 1) throw new InvalidArgumentException('Invalid checkout session.');

    return stripe_api('GET', 'checkout/sessions/' . rawurlencode($id));
}

/**
 * The card a customer pays with by default, or null. Looks at the subscription's default method
 * first, then the customer's invoice default. Needs a live call.
 *
 * @return array{id: string, label: string}|null
 */
function saved_card(string $accountId): ?array
{
    $account = stripe_account($accountId);
    $customerId = trim((string) ($account['stripe_customer_id'] ?? ''));
    if ($customerId === '' || !stripe_configured()) return null;
    try {
        $method = null;
        $subscriptionId = trim((string) ($account['subscription_id'] ?? ''));
        if ($subscriptionId !== '') {
            $subscription = stripe_api('GET', 'subscriptions/' . rawurlencode($subscriptionId),
                ['expand' => ['default_payment_method']]);
            $method = is_array($subscription['default_payment_method'] ?? null) ? $subscription['default_payment_method'] : null;
        }
        if ($method === null) {
            $customer = stripe_api('GET', 'customers/' . rawurlencode($customerId),
                ['expand' => ['invoice_settings.default_payment_method']]);
            $candidate = $customer['invoice_settings']['default_payment_method'] ?? null;
            $method = is_array($candidate) ? $candidate : null;
        }
        if ($method === null || ($method['type'] ?? '') !== 'card') return null;

        return ['id' => (string) $method['id'], 'label' => ucfirst((string) ($method['card']['brand'] ?? 'card'))
            . ' ending ' . (string) ($method['card']['last4'] ?? '')];
    } catch (Throwable $exception) {
        stripe_log('saved card lookup failed: ' . substr($exception->getMessage(), 0, 200));

        return null;
    }
}

/**
 * Charge the customer's saved card without them present (off-session). A bank that demands
 * authentication cannot be satisfied here: the result is ok=false with requires_action=true and
 * the host should bring the customer back on-session. Needs a live call.
 *
 * @return array{ok: bool, error?: string, status?: string, requires_action?: bool, intent?: array}
 */
function charge_saved_card(string $accountId, int $amountCents, string $idempotencyKey, array $opts = []): array
{
    if (!stripe_configured()) return stripe_not_configured();
    if ($accountId === '' || $amountCents < 1 || $idempotencyKey === '') {
        return ['ok' => false, 'error' => 'An account, a positive amount and an idempotency key are required.'];
    }
    $card = saved_card($accountId);
    if ($card === null) return ['ok' => false, 'error' => 'There is no saved card for this account.'];
    $params = payment_intent_params($accountId, $amountCents, $opts + [
        'customer' => (string) stripe_account($accountId)['stripe_customer_id']]);
    $params += ['payment_method' => $card['id'], 'off_session' => 'true', 'confirm' => 'true'];
    try {
        /* The card is part of the key: a retry after the customer changes a declined card is new. */
        $intent = stripe_api('POST', 'payment_intents', $params, 'saved:' . $card['id'] . ':' . $idempotencyKey);
    } catch (Throwable $exception) {
        stripe_log('charge_saved_card declined or failed: ' . substr($exception->getMessage(), 0, 200));

        return ['ok' => false, 'error' => 'The saved card did not accept the payment.'];
    }
    $status = (string) ($intent['status'] ?? '');
    $intentId = (string) ($intent['id'] ?? '');
    try {
        if ($intentId !== '') {
            transaction_record($accountId, (string) ($opts['kind'] ?? 'saved_card'), $amountCents, $params['currency'],
                $intentId, $status === 'succeeded' ? 'succeeded' : 'pending');
        }
    } catch (Throwable $exception) {
        /* The money moved at Stripe; the payment_intent.succeeded webhook records it if this failed. */
        stripe_log('saved card transaction not recorded locally: ' . substr($exception->getMessage(), 0, 200));
    }

    return ['ok' => $status === 'succeeded', 'status' => $status, 'requires_action' => $status === 'requires_action',
        'intent' => $intent] + ($status === 'succeeded' ? [] : ['error' => 'The payment was not completed.']);
}
