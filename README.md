# AI eBook Generation Stripe Management

Generic PHP Stripe payments, subscriptions, a reversible cancellation flow, refunds and verified webhooks on SQLite. It was extracted from a production app and stripped of everything app-specific.

- PHP 8.2+, `curl`, `pdo_sqlite`. **Zero Composer dependencies.** No Stripe SDK: a small raw `curl` client talks to `https://api.stripe.com/v1/`.
- Config from environment variables only. **No keys are included** in this repository.
- The package owns three generic SQLite tables keyed by *your* account id (stored as text). It never touches your users table.
- Webhooks are verified, claimed once, applied to the package's own tables, then handed to **handlers you register**. Your fulfilment and emails live in those handlers.

## Install

```bash
git clone https://github.com/WesBlackmore/ai-ebook-generation-stripe-management.git
```

```php
require '/path/to/ai-ebook-generation-stripe-management/src/autoload.php';
```

Or via Composer as a path/VCS repository (`"autoload": {"files": ["src/autoload.php"]}` is already set). The schema is created on first use of the database.

## Environment variables

| Variable | Purpose |
|---|---|
| `STRIPE_SECRET_KEY` | Server key. When unset, `stripe_configured()` is `false` and every write path returns `['ok' => false, 'error' => 'Stripe is not configured.']` instead of calling out. |
| `STRIPE_PUBLISHABLE_KEY` | Returned by `stripe_publishable_key()` for your front end. Not needed server side. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret of your webhook endpoint. When unset the webhook route answers `503` and processes nothing. |
| `STRIPE_CURRENCY` | Default currency, three letters. Default `usd`. |
| `STRIPE_MERCHANT_COUNTRY` | Two-letter country, via `stripe_merchant_country()`. Default `US`. |
| `APP_URL` | Base URL for default checkout success/cancel and portal return URLs, for example `https://shop.example.com`. |
| `STRIPE_MGMT_DB` | Optional SQLite path (or `:memory:`). Default `data/stripe-management.sqlite` inside the package, which is git-ignored. |

If you already have a PDO connection, call `stripe_db_use($pdo)` once at boot instead. The schema bootstraps on it.

## Schema

All ids from your app are `TEXT`. There are no foreign keys.

- `stripe_customers`: `account_id` (PK), `stripe_customer_id`, `subscription_id`, `subscription_status`, `subscription_plan`, `subscription_period_end`, `cancel_at_period_end`, plus `subscription_event_seq` (ordering key), `undo_token_hash` and `undo_expires_at` (the cancel-undo token, hash only).
- `transactions`: `id`, `account_id`, `kind`, `amount_cents`, `currency`, `stripe_intent_id` (unique), `status` (`pending`, `succeeded`, `failed`, `partially_refunded`, `refunded`), `refunded_cents`, `created_at`. For subscription payments `stripe_intent_id` holds the invoice id.
- `stripe_webhook_events`: `event_id` (PK), `received_at`, `processed_at`, `owner_token`, `claim_expires_at`, `released_at`, `attempts`.

## Webhooks

Point a Stripe webhook endpoint at a route of yours and call the entry point. The body must be the untouched request body.

```php
// webhook.php
require __DIR__ . '/vendor-or-path/src/autoload.php';

register_stripe_handler('payment_intent.succeeded', function (array $event, array $intent): void {
    // The package has already recorded the transaction. Do your fulfilment and emails here.
    grant_access($intent['metadata']['account_id'], $intent['id']);
});
register_stripe_handler('customer.subscription.updated', fn (array $e, array $sub) => sync_my_plan($sub));
register_stripe_handler('customer.subscription.deleted', fn (array $e, array $sub) => revoke_plan($sub));
register_stripe_handler('invoice.paid', fn (array $e, array $invoice) => send_receipt($invoice));
register_stripe_handler('charge.refunded', fn (array $e, array $charge) => note_refund($charge));

stripe_webhook_request(); // sets the HTTP status and exits
```

For a framework or tests use `stripe_webhook_handle($rawBody, $stripeSignatureHeader)`, which returns `['status' => int, 'reason' => string]` and never throws.

Enable at least these event types on the endpoint: `payment_intent.succeeded`, `payment_intent.payment_failed`, `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `charge.refunded`. Any other type can be handled by registering a handler for it; types with no handler are logged and acknowledged.

What the package does itself before your handlers run:

| Event | Package state change |
|---|---|
| `payment_intent.succeeded` | Record or upgrade the transaction to `succeeded` (needs `metadata.account_id`) |
| `payment_intent.payment_failed` | Pending transaction to `failed` |
| `checkout.session.completed` | Remember the Stripe customer for `client_reference_id` |
| `customer.subscription.created/updated/deleted` | Update `stripe_customers` (ordered, see below) |
| `invoice.paid` | Record one `subscription` transaction per invoice |
| `charge.refunded` | Raise `refunded_cents`, set `partially_refunded` or `refunded` |

Handlers are called as `$handler(array $event, array $object)`. Several per type are allowed. Because Stripe redelivers after a failure, **make handlers idempotent**.

Statuses returned: `200` processed, duplicate, or parked; `401` signature failed; `400` no usable event id; `413` body over 512 KB; `405` not POST; `503` secret not configured or a local failure worth retrying.

## Payments

```php
// Pure: show exactly what would be sent. Works without a key.
$params = checkout_session_params('payment', 'user-42', [['price' => 'price_...', 'quantity' => 1]]);

$result = create_payment_intent('user-42', 1999, 'order-1001', ['description' => 'Order 1001', 'metadata' => ['order' => '1001']]);
// ['ok' => true, 'intent' => [...], 'client_secret' => 'pi_..._secret_...', 'transaction_id' => 1]
```

The idempotency key is required. Stripe collapses a retry onto the first request only when the parameters match, so reuse the same key with the same arguments. A replayed intent does not create a second transaction row. Your `account_id` is always written to the intent metadata last, so host metadata cannot override it.

Hosted Checkout for a one-time payment: `create_payment_checkout($accountId, $lineItems, $successUrl, $cancelUrl)` returns `['ok' => true, 'url' => ...]`. Return URLs come from arguments or `APP_URL` (`/checkout/success?session_id={CHECKOUT_SESSION_ID}` and `/checkout/cancel`). The URL is only returned if it is an `https://checkout.stripe.com` address.

Saved card, off-session: `saved_card($accountId)` finds the default card, `charge_saved_card($accountId, $cents, $idempotencyKey)` charges it with `off_session` and `confirm`. A bank demanding authentication returns `requires_action => true`; bring the customer back on-session.

## Subscriptions and cancellation management

```php
// Start: send the browser to ['url'].
$r = begin_subscription_checkout('user-42', 'price_...', ['email' => 'a@example.com', 'plan_key' => 'pro']);

// Read: local, no network.
$account = stripe_account('user-42');          // subscription_status, subscription_period_end, cancel_at_period_end ...
subscription_is_active($account);

// Cancel at the end of the paid period (reversible, no refund, no immediate loss of access).
$c = subscription_set_cancel('user-42', true, 'too_expensive', 'optional comment');
// ['ok' => true, 'ends_at' => 1767225600, 'undo_token' => '<48 hex>']  -> put the token in your goodbye email

// Undo, by the emailed link (single use, expires in 24 hours) or by hand.
subscription_undo_cancel($token);
subscription_set_cancel('user-42', false);

// Explicit, separate, immediate. Needs confirm = true. No refund is issued.
subscription_cancel_immediately('user-42', true);

// Plan change, prorated and charged now. A declined card leaves the plan untouched.
subscription_change_plan('user-42', 'price_other');

// Stripe's own portal for cards and invoices.
$url = subscription_portal_url('user-42', '/billing');
```

Cancellation is built to be safe to offer:

1. `subscription_set_cancel(..., true)` only sets `cancel_at_period_end`. The plan stays active until the period ends.
2. It returns a random single-use undo token. Only its SHA-256 hash is stored. Redeeming it burns it atomically *before* Stripe is called, so a second click finds nothing; if Stripe fails, the token is re-armed with its original expiry.
3. Immediate cancellation is a different function that refuses to run without `$confirm === true`.
4. The package sends no email. Put `$c['undo_token']` in a link of your own and call `subscription_undo_cancel()` from that route.

Subscription writes are ordered. A webhook may only overwrite a subscription row with a strictly newer event (`created` seconds, then event id), so retries, reversed pairs and same-second deliveries resolve the same way every time. Our own writes are stamped as authoritative so a delayed older webhook cannot undo them. A `canceled` event for a subscription id the account does not hold is ignored, and an unpaid `incomplete` subscription never demotes a live plan. Both the Basil (`items.data[].current_period_end`, `parent.subscription_details`) and the older field shapes are read.

## Refunds

```php
refund_transaction('pi_...', 2000);   // partial, in cents
refund_transaction('pi_...');         // whatever remains
refund_transaction('in_...');         // a subscription payment, by its invoice id
```

The refund goes to Stripe first, then the local row. The guard is in the SQL: the refunded total may only grow and never exceed the amount paid, so negative, zero, over-sized and repeated refunds are refused (and an already-refunded payment never reaches Stripe). The idempotency key includes what was already refunded, so a retry dedupes and a later partial refund is a new one. `charge.refunded` webhooks set the absolute total from the charge, which makes redelivery harmless.

## Security properties

- **Signature verification** with `hash_hmac('sha256', "t.payload", secret)`, `Stripe-Signature` `t=`/`v1=` parsing (multiple `v1` values), constant-time compare, and a 300 second tolerance in both directions. Unsigned, late, future-dated, tampered or wrong-secret requests are rejected with `401` before anything is read from the payload.
- **Idempotent processing** through a lease on `stripe_webhook_events`. An event is claimed once with an `owner_token`. A redelivery while the claim is live or after completion is a safe `200`. A crashed claim expires (`claim_expires_at`) and can be re-claimed by a new owner; the old owner can no longer complete or release it.
- **Nothing throws out of the webhook path.** Local failures and throwing handlers are logged, the claim is released and the route answers `503` so Stripe retries. After 5 failed attempts the event is parked and answered `200`, so a poison event cannot retry forever.
- **Idempotency keys** on every write to Stripe, and duplicate-intent detection on the unique `stripe_intent_id`.
- Secrets are read with `getenv()` and never logged. Only the Stripe error type and code are logged, never request bodies.

## Live Stripe snippets (REQUIRE A TEST KEY, not run by this repo)

These call Stripe. Set `STRIPE_SECRET_KEY` to a **test-mode** key from your own dashboard first. Nothing below is executed by the self-check or the example.

```php
putenv('STRIPE_SECRET_KEY=<your test secret key>');
putenv('APP_URL=http://localhost:8000');

// 1. Hosted subscription checkout, then open $r['url'] in a browser and pay with a Stripe test card.
$r = begin_subscription_checkout('user-42', '<your test price id>', ['email' => 'test@example.com']);
echo $r['url'];

// 2. One-time PaymentIntent.
$p = create_payment_intent('user-42', 1999, 'demo-order-1');
echo $p['client_secret'];

// 3. After the webhook marks it succeeded: refund part of it.
refund_transaction($p['intent']['id'], 500);
```

To receive real events locally, forward them with the Stripe CLI (`stripe listen --forward-to localhost:8000/webhook.php`) and set `STRIPE_WEBHOOK_SECRET` to the secret it prints.

## Testing your own handlers offline

`stripe_set_transport(callable)` replaces the HTTP layer with your own fake (`fn($method, $path, $params, $idempotencyKey) => array`). `tools/selfcheck.php` uses it. Sign test payloads in-process with `hash_hmac('sha256', $t . '.' . $payload, $secret)`, as the example does.

## Run it

```bash
php tools/selfcheck.php          # assert-based, no network, non-zero exit on failure
php examples/offline_demo.php    # wiring demo, no key, no network
```

## Layout

```
src/stripe_client.php   config, curl client, URL guards
src/db.php              PDO + SQLite WAL, the 3 tables, small row helpers
src/payments.php        customers, PaymentIntents, Checkout, saved card
src/subscriptions.php   lifecycle, cancellation management, portal
src/refunds.php         refunds and local refund accounting
src/webhooks.php        verify, claim fence, state updates, handler registry
src/autoload.php        require this
tools/selfcheck.php     offline test suite
examples/offline_demo.php
```

## License

MIT, see `LICENSE`.
