<?php

declare(strict_types=1);

/** Config from the environment and the raw curl client for https://api.stripe.com/v1/. No Stripe SDK. */

function stripe_configured(): bool
{
    return (string) getenv('STRIPE_SECRET_KEY') !== '';
}

function stripe_publishable_key(): string
{
    return (string) getenv('STRIPE_PUBLISHABLE_KEY');
}

function stripe_currency(): string
{
    $currency = strtolower((string) getenv('STRIPE_CURRENCY'));

    return preg_match('/^[a-z]{3}$/', $currency) === 1 ? $currency : 'usd';
}

function stripe_merchant_country(): string
{
    $country = strtoupper((string) getenv('STRIPE_MERCHANT_COUNTRY'));

    return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'US';
}

/** An absolute URL under APP_URL, or the value itself when it already is absolute. */
function stripe_app_url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path) === 1) return $path;

    return rtrim((string) getenv('APP_URL'), '/') . '/' . ltrim($path, '/');
}

function stripe_log(string $message): void
{
    error_log('stripe-management: ' . $message);
}

/** The standard "not configured" result every high-level write path returns instead of throwing. */
function stripe_not_configured(): array
{
    return ['ok' => false, 'error' => 'Stripe is not configured.'];
}

/**
 * Replace the HTTP transport (tests, or a host that wants its own client). The callable receives
 * (method, path, params, idempotencyKey) and returns the decoded response array or throws.
 */
function stripe_set_transport(?callable $transport): void
{
    $GLOBALS['stripe_management_transport'] = $transport;
}

function stripe_api(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    $key = (string) getenv('STRIPE_SECRET_KEY');
    if ($key === '') throw new RuntimeException('Stripe is not configured.');
    $transport = $GLOBALS['stripe_management_transport'] ?? null;
    if (is_callable($transport)) return $transport($method, $path, $params, $idempotencyKey);

    $handle = curl_init();
    $headers = ['Authorization: Bearer ' . $key];
    if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    if ($method === 'POST' || $method === 'DELETE') {
        if ($method === 'POST') curl_setopt($handle, CURLOPT_POST, true);
        else curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($params));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    if ($method === 'GET' && $params) $url .= '?' . http_build_query($params);
    /* Bounded, because a person is usually waiting on the other end of this. */
    curl_setopt_array($handle, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    if ($body === false) {
        stripe_failure_record('unreachable', $path, 0, $error);

        throw new RuntimeException('Could not reach Stripe: ' . $error);
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        stripe_failure_record('unreadable', $path, $status, 'the response was not JSON');

        throw new RuntimeException('Stripe returned an unreadable response.');
    }
    if ($status >= 400) {
        /* Stripe's own type and code, not the sentence, because the sentence changes. */
        stripe_failure_record('rejected', $path, $status,
            trim((string) ($data['error']['type'] ?? '') . ' ' . (string) ($data['error']['code'] ?? '')));

        throw new RuntimeException('Stripe: ' . (string) ($data['error']['message'] ?? 'the request could not be completed.'));
    }

    return $data;
}

/** Every way a Stripe call can fail is logged here. Never the key, never the request body. */
function stripe_failure_record(string $kind, string $path, int $status, string $detail): void
{
    try {
        stripe_log(sprintf('STRIPE %s on %s (HTTP %d) %s', $kind, $path, $status, $detail));
    } catch (Throwable) {
        /* Recording a failure must never become a second failure. */
    }
}

/** Only a checkout.stripe.com https URL is ever handed to a browser as a redirect. */
function stripe_checkout_url(array $session): string
{
    $url = (string) ($session['url'] ?? '');
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || ($parts['host'] ?? '') !== 'checkout.stripe.com'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        throw new RuntimeException('Stripe did not return a secure checkout page.');
    }

    return $url;
}

/** Only Stripe's customer-facing hosted receipt is ever used as an external redirect. */
function stripe_hosted_receipt_url(array $charge): ?string
{
    $url = trim((string) ($charge['receipt_url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'pay.stripe.com') {
        return null;
    }

    return $url;
}
