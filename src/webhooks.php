<?php

declare(strict_types=1);

/**
 * Stripe webhook verification, the idempotent claim fence, the package's own state updates, and
 * dispatch to host-registered handlers. This route must receive the untouched request body.
 * Nothing here throws out of the request path: every outcome is an HTTP status.
 */

/** A failed delivery is retried by Stripe; after this many failures the event is parked, not retried forever. */
const STRIPE_WEBHOOK_MAX_ATTEMPTS = 5;

/* ---- Handler registry ---------------------------------------------------------------------- */

/**
 * Register a callback for a Stripe event type, e.g. 'payment_intent.succeeded'. Called as
 * `$handler(array $event, array $object)` after the package has updated its own tables. Several
 * handlers per type are allowed and run in registration order. A handler may be re-run when Stripe
 * redelivers after a failure, so make it idempotent.
 */
function register_stripe_handler(string $eventType, callable $handler): void
{
    $GLOBALS['stripe_management_handlers'][$eventType][] = $handler;
}

/** @return list<callable> */
function stripe_handlers_for(string $eventType): array
{
    return $GLOBALS['stripe_management_handlers'][$eventType] ?? [];
}

function stripe_handlers_clear(): void
{
    $GLOBALS['stripe_management_handlers'] = [];
}

/* ---- Signature ----------------------------------------------------------------------------- */

function stripe_webhook_secret(): string
{
    return (string) getenv('STRIPE_WEBHOOK_SECRET');
}

function stripe_signature_is_valid(string $payload, string $header, int $toleranceSeconds = 300): bool
{
    $secret = stripe_webhook_secret();
    if ($secret === '' || $header === '') return false;
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key][] = $value;
    }
    $timestamp = $parts['t'][0] ?? '';
    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $signature) if (hash_equals($expected, $signature)) return true;

    return false;
}

/* ---- Claim fence --------------------------------------------------------------------------- */

/**
 * Claim a webhook event for exclusive processing.
 *
 * Returns an owner token the caller holds for the lifetime of its work, or null when the event has
 * already been processed or another worker holds an unexpired claim. The token is checked on
 * complete and release: a worker whose claim was stolen (its lease expired) sees zero rows and bails
 * without stomping the new owner. A retry after the lease expires recovers the claim with a fresh token.
 */
function claim_stripe_webhook_event(string $eventId, int $ttlSeconds = 300): ?string
{
    $token = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(30, $ttlSeconds));
    try {
        stripe_db()->prepare('INSERT INTO stripe_webhook_events (event_id, owner_token, claim_expires_at)
            VALUES (?, ?, ?)')->execute([$eventId, $token, $expiresAt]);

        return $token;
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'UNIQUE constraint failed')) throw $exception;
    }
    /* A row exists. It is either already processed, held by a live owner, or stale. */
    $row = stripe_db()->prepare('SELECT processed_at, claim_expires_at, received_at
        FROM stripe_webhook_events WHERE event_id = ?');
    $row->execute([$eventId]);
    $held = $row->fetch();
    if (!$held || $held['processed_at'] !== null) return null;
    /* Steal only when the effective expiry has passed. A row with no expiry gets a one-hour grace
       from received_at. */
    $steal = stripe_db()->prepare('UPDATE stripe_webhook_events
        SET owner_token = :token, claim_expires_at = :expires, released_at = NULL
        WHERE event_id = :id
          AND processed_at IS NULL
          AND COALESCE(claim_expires_at,
                strftime(\'%Y-%m-%d %H:%M:%S\', received_at, \'+3600 seconds\')) <= :now');
    $steal->execute(['token' => $token, 'expires' => $expiresAt, 'id' => $eventId, 'now' => $now]);

    return $steal->rowCount() === 1 ? $token : null;
}

/** @return bool true when the caller still owned the claim and the row was marked processed. */
function complete_stripe_webhook_event(string $eventId, string $ownerToken): bool
{
    $statement = stripe_db()->prepare('UPDATE stripe_webhook_events
        SET processed_at = CURRENT_TIMESTAMP, released_at = CURRENT_TIMESTAMP
        WHERE event_id = ? AND owner_token = ? AND processed_at IS NULL');
    $statement->execute([$eventId, $ownerToken]);

    return $statement->rowCount() === 1;
}

/** Release a claim after a failure so Stripe's retry can run. Only the owner may release. */
function release_stripe_webhook_event(string $eventId, string $ownerToken): void
{
    stripe_db()->prepare('UPDATE stripe_webhook_events
        SET released_at = CURRENT_TIMESTAMP, claim_expires_at = :past, attempts = attempts + 1
        WHERE event_id = :id AND owner_token = :token AND processed_at IS NULL')
        ->execute(['past' => gmdate('Y-m-d H:i:s', time() - 1), 'id' => $eventId, 'token' => $ownerToken]);
}

/* ---- The package's own state updates --------------------------------------------------------- */

/** Update stripe_customers and transactions from the event. Throws on a local failure; the caller handles it. */
function stripe_event_apply_state(array $event): void
{
    $type = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? null;
    if (!is_array($object)) return;
    switch ($type) {
        case 'payment_intent.succeeded':
            $accountId = trim((string) ($object['metadata']['account_id'] ?? ''));
            $intentId = (string) ($object['id'] ?? '');
            /* A subscription invoice's PaymentIntent carries no account metadata; invoice.paid owns it. */
            if ($accountId === '' || $intentId === '') return;
            $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
            $recorded = transaction_record($accountId, (string) ($object['metadata']['kind'] ?? 'payment'),
                $amount, (string) ($object['currency'] ?? stripe_currency()), $intentId, 'succeeded');
            if ($recorded === null) transaction_mark_succeeded($intentId);
            return;
        case 'payment_intent.payment_failed':
            if (($object['id'] ?? '') !== '') transaction_mark_failed((string) $object['id']);
            return;
        case 'checkout.session.completed':
            $accountId = trim((string) ($object['client_reference_id'] ?? $object['metadata']['account_id'] ?? ''));
            $customer = $object['customer'] ?? '';
            if (is_array($customer)) $customer = $customer['id'] ?? '';
            if ($accountId !== '' && is_string($customer) && $customer !== '') {
                stripe_account_set($accountId, ['stripe_customer_id' => $customer]);
            }
            return;
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            apply_subscription_state($object, $event);
            return;
        case 'invoice.paid':
            $invoiceId = (string) ($object['id'] ?? '');
            $subscriptionId = invoice_subscription_id($object);
            if ($invoiceId === '' || $subscriptionId === '') return;
            $accountId = trim((string) ($object['parent']['subscription_details']['metadata']['account_id']
                ?? $object['subscription_details']['metadata']['account_id'] ?? ''));
            if ($accountId === '') $accountId = subscription_account_id(['id' => $subscriptionId,
                'customer' => $object['customer'] ?? '']);
            if ($accountId === '') return;
            /* Keyed on the invoice: next month's renewal records again, a redelivery does not. */
            transaction_record($accountId, 'subscription', (int) ($object['amount_paid'] ?? 0),
                (string) ($object['currency'] ?? stripe_currency()), $invoiceId, 'succeeded');
            return;
        case 'charge.refunded':
            refund_apply_charge($object);
            return;
    }
}

/** Run every registered handler for the event. @return int how many handlers threw */
function stripe_event_dispatch(array $event): int
{
    $type = (string) ($event['type'] ?? '');
    $handlers = stripe_handlers_for($type);
    if (!$handlers) {
        stripe_log('no handler registered for ' . ($type !== '' ? $type : '(untyped event)') . ', nothing to do');

        return 0;
    }
    $failures = 0;
    foreach ($handlers as $handler) {
        try {
            $handler($event, is_array($event['data']['object'] ?? null) ? $event['data']['object'] : []);
        } catch (Throwable $exception) {
            $failures++;
            stripe_log('handler for ' . $type . ' threw ' . $exception::class . ': ' . substr($exception->getMessage(), 0, 200));
        }
    }

    return $failures;
}

/* ---- Entry points ---------------------------------------------------------------------------- */

function stripe_webhook_refuse(string $reason, int $status): array
{
    stripe_log(sprintf('WEBHOOK REFUSED %s (HTTP %d)', $reason, $status));

    return ['status' => $status, 'reason' => $reason];
}

/**
 * Process one delivery and return its HTTP status. Never throws.
 *
 * 503 secret not configured or a local failure worth retrying; 413 too large; 401 bad signature;
 * 400 no usable event id; 200 processed, duplicate, or parked after repeated failures.
 *
 * @return array{status: int, reason: string}
 */
function stripe_webhook_handle(string $payload, string $signatureHeader): array
{
    try {
        if (stripe_webhook_secret() === '') return stripe_webhook_refuse('the signing secret is not configured', 503);
        if (strlen($payload) > 512 * 1024) return stripe_webhook_refuse('the body was too large', 413);
        if (!stripe_signature_is_valid($payload, $signatureHeader)) {
            return stripe_webhook_refuse('the signature did not verify, so the secret here and at Stripe disagree', 401);
        }
        $event = json_decode($payload, true);
        $eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
        if ($eventId === '' || preg_match('/^evt_[A-Za-z0-9_]+$/', $eventId) !== 1) {
            return stripe_webhook_refuse('the event carried no usable id', 400);
        }
        /* A duplicate that is already processed, or whose claim is held by a live worker, is
           acknowledged with 200 so Stripe's retry does not storm a healthy in-flight worker. */
        $ownerToken = claim_stripe_webhook_event($eventId);
        if ($ownerToken === null) return ['status' => 200, 'reason' => 'duplicate'];
    } catch (Throwable $exception) {
        stripe_log('webhook setup failed: ' . $exception::class . ' ' . substr($exception->getMessage(), 0, 200));

        return ['status' => 503, 'reason' => 'local failure before processing'];
    }

    try {
        stripe_event_apply_state($event);
        $failed = stripe_event_dispatch($event);
        if ($failed > 0) throw new RuntimeException($failed . ' handler(s) failed');
        complete_stripe_webhook_event($eventId, $ownerToken);

        return ['status' => 200, 'reason' => 'processed'];
    } catch (Throwable $exception) {
        stripe_log('webhook ' . $eventId . ' failed: ' . $exception::class . ' ' . substr($exception->getMessage(), 0, 200));
        try {
            release_stripe_webhook_event($eventId, $ownerToken);
            $attempts = stripe_db()->prepare('SELECT attempts FROM stripe_webhook_events WHERE event_id = ?');
            $attempts->execute([$eventId]);
            if ((int) $attempts->fetchColumn() >= STRIPE_WEBHOOK_MAX_ATTEMPTS) {
                /* Parked: the cause is not going away on its own, so stop Stripe's retries. The
                   row is marked processed; the log line above is the record. */
                $stolen = claim_stripe_webhook_event($eventId, 30);
                if ($stolen !== null) complete_stripe_webhook_event($eventId, $stolen);
                stripe_log('webhook ' . $eventId . ' parked after ' . STRIPE_WEBHOOK_MAX_ATTEMPTS . ' failed attempts');

                return ['status' => 200, 'reason' => 'parked after repeated failure'];
            }
        } catch (Throwable $inner) {
            stripe_log('could not release webhook claim: ' . substr($inner->getMessage(), 0, 200));
        }

        return ['status' => 503, 'reason' => 'processing failed, claim released for retry'];
    }
}

/** The route: POST here with the untouched body. Sets the status code and exits. */
function stripe_webhook_request(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 512 * 1024) {
        $result = stripe_webhook_refuse('the declared body was too large', 413);
    } else {
        $result = stripe_webhook_handle((string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    }
    http_response_code($result['status']);
    exit;
}
