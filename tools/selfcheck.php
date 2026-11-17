<?php

declare(strict_types=1);

/** Offline self-check. No framework, no network. Exits non-zero on any failure: php tools/selfcheck.php */

require __DIR__ . '/../src/autoload.php';

ini_set('error_log', sys_get_temp_dir() . '/stripe-management-selfcheck.log');
$failures = 0;
$total = 0;
function check(bool $condition, string $label): void
{
    global $failures, $total;
    $total++;
    if (!$condition) $failures++;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . "\n";
}

const SELFCHECK_SECRET = 'whsec_testselfcheck';
const SELFCHECK_KEY = 'selfcheck-not-a-real-key';

function sign(string $payload, ?int $timestamp = null, string $secret = SELFCHECK_SECRET): string
{
    $t = (string) ($timestamp ?? time());

    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
}

function event(string $id, string $type, array $object, ?int $created = null): string
{
    return json_encode(['id' => $id, 'type' => $type, 'created' => $created ?? time(),
        'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
}

/** A fresh in-memory database, no handlers, no transport, the test secret set, no Stripe key. */
function fresh(bool $withKey = false): void
{
    stripe_db_use(new PDO('sqlite::memory:'));
    stripe_handlers_clear();
    stripe_set_transport(null);
    putenv('STRIPE_WEBHOOK_SECRET=' . SELFCHECK_SECRET);
    putenv('APP_URL=https://app.example.test');
    putenv($withKey ? 'STRIPE_SECRET_KEY=' . SELFCHECK_KEY : 'STRIPE_SECRET_KEY');
}

/** Install a fake Stripe and return a reference to the list of calls it receives. */
function fake_stripe(callable $respond): array
{
    $calls = [];
    stripe_set_transport(function (string $method, string $path, array $params, ?string $key) use (&$calls, $respond): array {
        $calls[] = ['method' => $method, 'path' => $path, 'params' => $params, 'key' => $key];

        return $respond($method, $path, $params);
    });
    $GLOBALS['fake_calls'] = &$calls;

    return $calls;
}

function table_count(string $table): int
{
    return (int) stripe_db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

/* ---- schema ---- */
fresh();
$tables = stripe_db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
check($tables === ['stripe_customers', 'stripe_webhook_events', 'transactions'], 'schema bootstraps exactly the 3 tables');
stripe_schema_bootstrap(stripe_db());
check(true, 'schema bootstrap is idempotent');
check(transaction_record('acct-1', 'payment', 1000, 'USD', 'pi_dup1') > 0
    && transaction_record('acct-1', 'payment', 1000, 'usd', 'pi_dup1') === null, 'duplicate intent id is detected, not thrown');

/* ---- webhook: valid, bad, tampered, expired ---- */
fresh();
$seen = [];
register_stripe_handler('payment_intent.succeeded', function (array $event, array $object) use (&$seen): void {
    $seen[] = $event['id'] . ':' . $object['id'];
});
$payload = event('evt_valid1', 'payment_intent.succeeded',
    ['id' => 'pi_valid1', 'amount' => 2500, 'currency' => 'usd', 'metadata' => ['account_id' => 'acct-1']]);
$result = stripe_webhook_handle($payload, sign($payload));
check($result['status'] === 200 && $seen === ['evt_valid1:pi_valid1'], 'valid signature verifies and dispatches to the handler');
$row = transaction_by_intent('pi_valid1');
check($row !== null && $row['status'] === 'succeeded' && (int) $row['amount_cents'] === 2500 && $row['account_id'] === 'acct-1',
    'package state updated before dispatch (transaction recorded as succeeded)');

$again = stripe_webhook_handle($payload, sign($payload));
check($again['status'] === 200 && $again['reason'] === 'duplicate' && count($seen) === 1,
    're-delivering the same event id is a no-op (claim fence)');

$other = event('evt_bad1', 'payment_intent.succeeded', ['id' => 'pi_bad1', 'amount' => 1, 'metadata' => ['account_id' => 'a']]);
check(stripe_webhook_handle($other, sign($other, null, 'a-different-wrong-secret'))['status'] === 401, 'bad signature is rejected with 401');
check(stripe_webhook_handle($other, '')['status'] === 401, 'missing signature header is rejected with 401');
check(stripe_webhook_handle($other, 't=abc,v1=00')['status'] === 401, 'malformed signature header is rejected with 401');
$signed = sign($other);
check(stripe_webhook_handle($other . ' ', $signed)['status'] === 401, 'tampered payload is rejected with 401');
check(stripe_webhook_handle($other, sign($other, time() - 3600))['status'] === 401, 'expired timestamp is rejected with 401');
check(stripe_webhook_handle($other, sign($other, time() + 3600))['status'] === 401, 'future timestamp is rejected with 401');
check(count($seen) === 1 && transaction_by_intent('pi_bad1') === null && table_count('stripe_webhook_events') === 1,
    'nothing from a rejected delivery was processed or claimed');
$noId = json_encode(['type' => 'x', 'data' => ['object' => []]]);
check(stripe_webhook_handle($noId, sign($noId))['status'] === 400, 'a signed event without a usable id is rejected with 400');
putenv('STRIPE_WEBHOOK_SECRET');
check(stripe_webhook_handle($other, sign($other))['status'] === 503, 'unset webhook secret answers 503, never processes');
putenv('STRIPE_WEBHOOK_SECRET=' . SELFCHECK_SECRET);
check(stripe_webhook_handle(str_repeat('x', 600 * 1024), 't=1,v1=a')['status'] === 413, 'oversized body is rejected with 413');

/* ---- missing handler is a no-op ---- */
fresh();
$noop = event('evt_nohandler', 'some.unhandled.type', ['id' => 'x_1']);
check(stripe_webhook_handle($noop, sign($noop))['status'] === 200, 'an event type with no handler is a logged no-op, not an error');

/* ---- crashed claim can be re-claimed ---- */
fresh();
$first = claim_stripe_webhook_event('evt_crash1');
check($first !== null && claim_stripe_webhook_event('evt_crash1') === null, 'a live claim turns a second worker away');
stripe_db()->prepare('UPDATE stripe_webhook_events SET claim_expires_at = ? WHERE event_id = ?')
    ->execute([gmdate('Y-m-d H:i:s', time() - 10), 'evt_crash1']);
$second = claim_stripe_webhook_event('evt_crash1');
check($second !== null && $second !== $first, 'a crashed claim past claim_expires_at can be re-claimed with a new token');
check(complete_stripe_webhook_event('evt_crash1', $first) === false, 'the stolen-from worker cannot complete the event');
check(complete_stripe_webhook_event('evt_crash1', $second) === true && claim_stripe_webhook_event('evt_crash1') === null,
    'the new owner completes it and it is never claimed again');
$ran = 0;
register_stripe_handler('invoice.paid', function () use (&$ran): void { $ran++; });
claim_stripe_webhook_event('evt_crash2');
stripe_db()->prepare('UPDATE stripe_webhook_events SET claim_expires_at = ? WHERE event_id = ?')
    ->execute([gmdate('Y-m-d H:i:s', time() - 10), 'evt_crash2']);
$crashed = event('evt_crash2', 'invoice.paid', ['id' => 'in_c2']);
check(stripe_webhook_handle($crashed, sign($crashed))['status'] === 200 && $ran === 1, 'a redelivery after a crash is processed through the entry point');

/* ---- failing handler: 503 then retry, then parked ---- */
fresh();
$fail = true;
$handled = 0;
register_stripe_handler('charge.refunded', function () use (&$fail, &$handled): void {
    $handled++;
    if ($fail) throw new RuntimeException('host exploded');
});
$bad = event('evt_throw1', 'charge.refunded', ['id' => 'ch_1', 'amount_refunded' => 0]);
check(stripe_webhook_handle($bad, sign($bad))['status'] === 503, 'a throwing handler never escapes: the webhook answers 503');
$fail = false;
check(stripe_webhook_handle($bad, sign($bad))['status'] === 200 && $handled === 2, 'the released claim lets the retry succeed');
fresh();
register_stripe_handler('charge.refunded', function (): void { throw new RuntimeException('always'); });
$poison = event('evt_poison', 'charge.refunded', ['id' => 'ch_2']);
$statuses = [];
for ($i = 0; $i < STRIPE_WEBHOOK_MAX_ATTEMPTS; $i++) $statuses[] = stripe_webhook_handle($poison, sign($poison))['status'];
check(array_slice($statuses, 0, -1) === array_fill(0, STRIPE_WEBHOOK_MAX_ATTEMPTS - 1, 503) && end($statuses) === 200,
    'a poison event is retried a bounded number of times, then parked with 200');
check(stripe_webhook_handle($poison, sign($poison))['reason'] === 'duplicate', 'a parked event is not claimed again');

/* ---- unconfigured: everything guards ---- */
fresh(false);
check(stripe_configured() === false, 'stripe_configured() is false with no secret key');
check(create_payment_intent('a', 500, 'k')['ok'] === false, 'create_payment_intent guards when unconfigured');
check(create_payment_checkout('a', [['price' => 'price_1', 'quantity' => 1]])['ok'] === false, 'checkout guards when unconfigured');
check(begin_subscription_checkout('a', 'price_1')['ok'] === false, 'subscription checkout guards when unconfigured');
check(subscription_set_cancel('a', true)['ok'] === false, 'cancel guards when unconfigured');
check(subscription_cancel_immediately('a', true)['ok'] === false, 'immediate cancel guards when unconfigured');
check(subscription_change_plan('a', 'price_2')['ok'] === false, 'plan change guards when unconfigured');
check(refund_transaction('pi_x')['ok'] === false, 'refund guards when unconfigured');
check(charge_saved_card('a', 500, 'k')['ok'] === false, 'saved-card charge guards when unconfigured');
check(subscription_portal_url('a') === null, 'portal url is null when unconfigured');
$threw = false;
try { stripe_api('GET', 'customers'); } catch (RuntimeException) { $threw = true; }
check($threw, 'the raw client refuses to call out without a key');

/* ---- idempotency keys and request shape ---- */
fresh(true);
fake_stripe(fn ($m, $p, $params) => match (true) {
    $p === 'payment_intents' => ['id' => 'pi_made1', 'client_secret' => 'pi_made1_secret_x', 'status' => 'requires_payment_method'],
    default => [],
});
$made = create_payment_intent('acct-9', 1999, 'order-77', ['description' => 'Test', 'metadata' => ['order' => '77', 'account_id' => 'evil']]);
$call = $GLOBALS['fake_calls'][0];
check($made['ok'] && $call['key'] === 'order-77' && $call['params']['amount'] === 1999 && $call['params']['currency'] === 'usd',
    'create_payment_intent sends the idempotency key, amount and default currency');
check($call['params']['metadata']['account_id'] === 'acct-9' && $call['params']['metadata']['order'] === '77',
    'host metadata can never override the account that owns the payment');
$pending = transaction_by_intent('pi_made1');
check($pending !== null && $pending['status'] === 'pending', 'a pending transaction is recorded at creation');
create_payment_intent('acct-9', 1999, 'order-77');
check(table_count('transactions') === 1, 'a replayed intent does not create a second transaction');
check(create_payment_intent('acct-9', 0, 'k')['ok'] === false && create_payment_intent('acct-9', 10, '')['ok'] === false,
    'zero amount or missing idempotency key is refused before Stripe is called');
$params = checkout_session_params('subscription', 'acct-9', [['price' => 'price_1', 'quantity' => 1]]);
check($params['success_url'] === 'https://app.example.test/checkout/success?session_id={CHECKOUT_SESSION_ID}'
    && $params['cancel_url'] === 'https://app.example.test/checkout/cancel'
    && $params['subscription_data']['metadata']['account_id'] === 'acct-9', 'checkout params take return URLs from APP_URL and carry the account');
$params = checkout_session_params('payment', 'acct-9', [['price' => 'price_1', 'quantity' => 1]], '/ok', 'https://other.example.test/no');
check($params['success_url'] === 'https://app.example.test/ok' && $params['cancel_url'] === 'https://other.example.test/no',
    'checkout return URLs can be passed as arguments');
$bogus = false;
try { stripe_checkout_url(['url' => 'https://evil.example.test/pay']); } catch (RuntimeException) { $bogus = true; }
check($bogus, 'a non-Stripe checkout URL is never returned for redirect');

/* ---- subscription state, cancel, undo ---- */
fresh(true);
$sub = fn (string $status, bool $cancelAtEnd, int $end) => ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status,
    'cancel_at_period_end' => $cancelAtEnd, 'metadata' => ['account_id' => 'acct-s', 'plan_key' => 'pro'],
    'items' => ['data' => [['id' => 'si_1', 'current_period_end' => $end, 'price' => ['id' => 'price_pro']]]]];
$periodEnd = time() + 20 * 86400;
$created = event('evt_sub1', 'customer.subscription.created', $sub('active', false, $periodEnd), time() - 100);
check(stripe_webhook_handle($created, sign($created))['status'] === 200, 'subscription event is accepted');
$account = stripe_account('acct-s');
check($account['subscription_status'] === 'active' && $account['subscription_plan'] === 'pro'
    && $account['stripe_customer_id'] === 'cus_1' && (int) $account['cancel_at_period_end'] === 0
    && strtotime($account['subscription_period_end'] . ' UTC') === $periodEnd, 'subscription state lands in stripe_customers (Basil period end read)');
$stale = event('evt_sub0', 'customer.subscription.updated', $sub('canceled', false, $periodEnd), time() - 500);
stripe_webhook_handle($stale, sign($stale));
check(stripe_account('acct-s')['subscription_status'] === 'active', 'an older event delivered late does not overwrite newer state');
$orphan = event('evt_orph', 'customer.subscription.updated',
    ['id' => 'sub_other', 'customer' => 'cus_1', 'status' => 'canceled', 'metadata' => ['account_id' => 'acct-s']]);
stripe_webhook_handle($orphan, sign($orphan));
check(stripe_account('acct-s')['subscription_id'] === 'sub_1', 'a canceled event for a subscription we do not hold is ignored');
check(begin_subscription_checkout('acct-s', 'price_pro')['ok'] === false, 'checkout is refused when the account already has a live plan');

$stripeFails = false;
fake_stripe(function (string $m, string $p, array $params) use (&$stripeFails, $sub, $periodEnd): array {
    if ($stripeFails) throw new RuntimeException('Stripe is down');
    if ($m === 'POST' && $p === 'subscriptions/sub_1') return $sub('active', $params['cancel_at_period_end'] === 'true', $periodEnd);
    if ($m === 'DELETE' && $p === 'subscriptions/sub_1') return $sub('canceled', false, $periodEnd);

    return [];
});
$cancel = subscription_set_cancel('acct-s', true, 'too_expensive', 'pricey');
check($cancel['ok'] && $cancel['ends_at'] === $periodEnd && strlen((string) $cancel['undo_token']) === 48, 'cancel at period end returns the end date and an undo token');
$sent = $GLOBALS['fake_calls'][0];
check($sent['params']['cancel_at_period_end'] === 'true' && $sent['params']['cancellation_details']['feedback'] === 'too_expensive'
    && str_starts_with((string) $sent['key'], 'plan-state:sub_1:'), 'cancel is at period end with feedback and an idempotency key, not immediate');
check((int) stripe_account('acct-s')['cancel_at_period_end'] === 1 && stripe_account('acct-s')['subscription_status'] === 'active',
    'local state shows cancel_at_period_end while the plan stays active');
check(stripe_db()->query('SELECT COUNT(*) FROM stripe_customers WHERE undo_token_hash = ' . stripe_db()->quote($cancel['undo_token']))->fetchColumn() == 0,
    'the undo token is stored only as a hash');
$undo = subscription_undo_cancel($cancel['undo_token']);
check($undo['ok'] && $undo['account_id'] === 'acct-s' && (int) stripe_account('acct-s')['cancel_at_period_end'] === 0,
    'the undo token resumes the plan');
check(subscription_undo_cancel($cancel['undo_token'])['ok'] === false, 'the undo token is single-use');
check(subscription_undo_cancel('not-a-token')['ok'] === false, 'a malformed undo token is refused');
$cancel2 = subscription_set_cancel('acct-s', true);
stripe_db()->prepare('UPDATE stripe_customers SET undo_expires_at = ? WHERE account_id = ?')->execute([time() - 5, 'acct-s']);
check(subscription_undo_cancel($cancel2['undo_token'])['ok'] === false, 'an expired undo token is refused');
$cancel3 = subscription_set_cancel('acct-s', true);
$stripeFails = true;
check(subscription_undo_cancel($cancel3['undo_token'])['ok'] === false, 'an undo that Stripe rejects reports failure');
$stripeFails = false;
check(subscription_undo_cancel($cancel3['undo_token'])['ok'] === true, 'and the token is re-armed so the customer can try again');
check(subscription_cancel_immediately('acct-s')['ok'] === false && stripe_account('acct-s')['subscription_status'] === 'active',
    'immediate cancel needs explicit confirmation');
check(subscription_cancel_immediately('acct-s', true)['ok'] === true && stripe_account('acct-s')['subscription_status'] === 'canceled',
    'immediate cancel is a separate explicit call that ends the plan');
check(subscription_set_cancel('acct-s', true)['ok'] === false, 'a canceled plan cannot be cancelled again');

/* ---- refunds ---- */
fresh(true);
$paid = event('evt_pay1', 'payment_intent.succeeded', ['id' => 'pi_ref1', 'amount' => 5000, 'currency' => 'usd', 'metadata' => ['account_id' => 'acct-r']]);
stripe_webhook_handle($paid, sign($paid));
fake_stripe(fn ($m, $p, $params) => $p === 'refunds' ? ['id' => 're_1', 'status' => 'succeeded', 'amount' => $params['amount']] : []);
$partial = refund_transaction('pi_ref1', 2000);
check($partial['ok'] && $partial['status'] === 'partially_refunded' && $partial['refunded_cents'] === 2000, 'a partial refund updates the transaction status');
check($GLOBALS['fake_calls'][0]['key'] === 'refund:pi_ref1:0:2000' && $GLOBALS['fake_calls'][0]['params']['payment_intent'] === 'pi_ref1',
    'the refund goes through Stripe with an idempotency key');
$over = refund_transaction('pi_ref1', 4000);
check($over['ok'] === false && count($GLOBALS['fake_calls']) === 1, 'a refund larger than what remains is refused before Stripe is called');
check(refund_transaction('pi_ref1', -5)['ok'] === false && refund_transaction('pi_ref1', 0)['ok'] === false, 'negative and zero refunds are refused');
$rest = refund_transaction('pi_ref1');
check($rest['ok'] && $rest['status'] === 'refunded' && $rest['refunded_cents'] === 5000, 'refunding with no amount refunds exactly what remains');
$double = refund_transaction('pi_ref1');
check($double['ok'] === false && count($GLOBALS['fake_calls']) === 2, 'a fully refunded payment is never refunded twice');
check(refund_transaction('pi_unknown')['ok'] === false, 'refunding an unknown payment is refused');
$webhookRefund = event('evt_ref2', 'charge.refunded', ['id' => 'ch_1', 'payment_intent' => 'pi_ref1', 'amount_refunded' => 5000, 'refunded' => true]);
check(stripe_webhook_handle($webhookRefund, sign($webhookRefund))['status'] === 200 && transaction_by_intent('pi_ref1')['status'] === 'refunded',
    'charge.refunded redelivery leaves a refunded row refunded');
$late = event('evt_pay2', 'payment_intent.succeeded', ['id' => 'pi_ref1', 'amount' => 5000, 'currency' => 'usd', 'metadata' => ['account_id' => 'acct-r']]);
stripe_webhook_handle($late, sign($late));
check(transaction_by_intent('pi_ref1')['status'] === 'refunded', 'a late succeeded event cannot move a refunded payment back');
$p2 = event('evt_pay3', 'payment_intent.succeeded', ['id' => 'pi_ref2', 'amount' => 3000, 'currency' => 'usd', 'metadata' => ['account_id' => 'acct-r']]);
stripe_webhook_handle($p2, sign($p2));
$r2 = event('evt_ref3', 'charge.refunded', ['id' => 'ch_2', 'payment_intent' => 'pi_ref2', 'amount_refunded' => 1000, 'refunded' => false]);
stripe_webhook_handle($r2, sign($r2));
$r2b = event('evt_ref4', 'charge.refunded', ['id' => 'ch_2', 'payment_intent' => 'pi_ref2', 'amount_refunded' => 500, 'refunded' => false]);
stripe_webhook_handle($r2b, sign($r2b));
$r2c = event('evt_ref5', 'charge.refunded', ['id' => 'ch_2', 'payment_intent' => 'pi_ref2', 'amount_refunded' => 99999, 'refunded' => true]);
stripe_webhook_handle($r2c, sign($r2c));
$row2 = transaction_by_intent('pi_ref2');
check($row2['status'] === 'partially_refunded' && (int) $row2['refunded_cents'] === 1000,
    'webhook refunds only grow the total and never exceed the amount paid');
$inv = event('evt_inv1', 'invoice.paid', ['id' => 'in_9', 'amount_paid' => 900, 'currency' => 'usd', 'customer' => 'cus_none',
    'parent' => ['subscription_details' => ['subscription' => 'sub_9', 'metadata' => ['account_id' => 'acct-r']]]]);
stripe_webhook_handle($inv, sign($inv));
stripe_webhook_handle($inv, sign($inv));
$invRow = transaction_by_intent('in_9');
check($invRow !== null && $invRow['kind'] === 'subscription' && table_count('transactions') === 3, 'invoice.paid records one subscription transaction keyed on the invoice');
$r3 = event('evt_ref6', 'charge.refunded', ['id' => 'ch_3', 'payment_intent' => 'pi_inv', 'invoice' => 'in_9', 'amount_refunded' => 900, 'refunded' => true]);
stripe_webhook_handle($r3, sign($r3));
check(transaction_by_intent('in_9')['status'] === 'refunded', 'a refunded subscription charge is routed to its invoice transaction');

/* ---- saved card ---- */
fresh(true);
stripe_account_set('acct-c', ['stripe_customer_id' => 'cus_c']);
fake_stripe(function (string $m, string $p, array $params): array {
    if ($p === 'customers/cus_c') return ['invoice_settings' => ['default_payment_method' =>
        ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]]];
    if ($p === 'payment_intents') return ['id' => 'pi_saved1', 'status' => 'succeeded'];

    return [];
});
$charged = charge_saved_card('acct-c', 700, 'topup-1');
$last = end($GLOBALS['fake_calls']);
check($charged['ok'] && $last['params']['off_session'] === 'true' && $last['params']['payment_method'] === 'pm_1'
    && $last['params']['customer'] === 'cus_c' && $last['key'] === 'saved:pm_1:topup-1', 'off-session charge uses the saved method, customer and a keyed request');
check(transaction_by_intent('pi_saved1')['status'] === 'succeeded', 'the saved-card payment is recorded');
check(charge_saved_card('nobody', 700, 'k')['ok'] === false, 'no saved card means no charge');

echo "\n" . ($total - $failures) . '/' . $total . " checks passed\n";
exit($failures === 0 ? 0 : 1);
