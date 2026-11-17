<?php

declare(strict_types=1);

/**
 * Runs with NO Stripe key and NO network: php examples/offline_demo.php
 * Shows handler registration, schema bootstrap, a locally signed webhook, dedupe on redelivery,
 * and the Checkout Session params a live call would send.
 */

require __DIR__ . '/../src/autoload.php';

putenv('STRIPE_WEBHOOK_SECRET=example-only-webhook-secret');
putenv('APP_URL=https://shop.example.test');
putenv('STRIPE_MGMT_DB=:memory:');

echo "1. Schema bootstrap\n";
$tables = stripe_db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
echo '   tables: ' . implode(', ', $tables) . "\n";
echo '   stripe_configured(): ' . var_export(stripe_configured(), true) . " (no STRIPE_SECRET_KEY set)\n\n";

echo "2. Register handlers (the host does its own fulfilment in here)\n";
register_stripe_handler('payment_intent.succeeded', function (array $event, array $intent): void {
    echo '   [handler] fulfil order for account ' . $intent['metadata']['account_id']
        . ', ' . $intent['amount'] . ' ' . $intent['currency'] . ' (event ' . $event['id'] . ")\n";
});
register_stripe_handler('customer.subscription.updated', function (array $event, array $subscription): void {
    echo '   [handler] subscription ' . $subscription['id'] . ' is now ' . $subscription['status'] . "\n";
});

echo "\n3. Deliver a locally signed webhook\n";
$payload = json_encode(['id' => 'evt_example1', 'type' => 'payment_intent.succeeded', 'created' => time(),
    'data' => ['object' => ['id' => 'pi_example1', 'amount' => 1900, 'currency' => 'usd',
        'metadata' => ['account_id' => 'user-42']]]]);
$t = (string) time();
$header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'example-only-webhook-secret');
$first = stripe_webhook_handle($payload, $header);
echo '   first delivery:  HTTP ' . $first['status'] . ' (' . $first['reason'] . ")\n";
$second = stripe_webhook_handle($payload, $header);
echo '   redelivery:      HTTP ' . $second['status'] . ' (' . $second['reason'] . ") and the handler did not run again\n";
$forged = stripe_webhook_handle($payload, 't=' . $t . ',v1=' . str_repeat('0', 64));
echo '   forged header:   HTTP ' . $forged['status'] . ' (' . $forged['reason'] . ")\n";
$row = transaction_by_intent('pi_example1');
echo '   transaction row: ' . $row['status'] . ', ' . $row['amount_cents'] . ' ' . $row['currency'] . ', account ' . $row['account_id'] . "\n";

echo "\n4. Checkout Session params a live call would send (nothing is sent)\n";
echo json_encode(checkout_session_params('subscription', 'user-42', [['price' => 'price_REPLACE_ME', 'quantity' => 1]]),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
