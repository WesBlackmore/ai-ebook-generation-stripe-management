<?php

declare(strict_types=1);

/** PDO + SQLite (WAL) and the three generic tables. Account ids are host-supplied TEXT, no foreign keys. */

function stripe_db_use(?PDO $pdo): void
{
    $GLOBALS['stripe_management_pdo'] = $pdo;
    if ($pdo !== null) stripe_schema_bootstrap($pdo);
}

function stripe_db(): PDO
{
    $pdo = $GLOBALS['stripe_management_pdo'] ?? null;
    if ($pdo instanceof PDO) return $pdo;
    $path = (string) getenv('STRIPE_MGMT_DB');
    if ($path === '') $path = dirname(__DIR__) . '/data/stripe-management.sqlite';
    if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    stripe_schema_bootstrap($pdo);

    return $GLOBALS['stripe_management_pdo'] = $pdo;
}

/** Idempotent: safe to run on every boot. */
function stripe_schema_bootstrap(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE IF NOT EXISTS stripe_customers (
        account_id TEXT PRIMARY KEY,
        stripe_customer_id TEXT,
        subscription_id TEXT,
        subscription_status TEXT,
        subscription_plan TEXT,
        subscription_period_end TEXT,
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        subscription_event_seq TEXT,
        undo_token_hash TEXT,
        undo_expires_at INTEGER,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS stripe_customers_customer ON stripe_customers(stripe_customer_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS stripe_customers_subscription ON stripe_customers(subscription_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id TEXT NOT NULL,
        kind TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        currency TEXT NOT NULL,
        stripe_intent_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        refunded_cents INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS transactions_stripe_intent_id_unique
        ON transactions(stripe_intent_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS transactions_account ON transactions(account_id)');
    /* The claim fences: owner_token is the lease, claim_expires_at its expiry, released_at the
       release stamp. `attempts` counts failed deliveries so a poison event stops retrying. */
    $pdo->exec('CREATE TABLE IF NOT EXISTS stripe_webhook_events (
        event_id TEXT PRIMARY KEY,
        received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at TEXT,
        owner_token TEXT,
        claim_expires_at TEXT,
        released_at TEXT,
        attempts INTEGER NOT NULL DEFAULT 0
    )');
}

/** @return array<string, mixed> the account's stripe_customers row, or [] when it has none. */
function stripe_account(string $accountId): array
{
    $row = stripe_db()->prepare('SELECT * FROM stripe_customers WHERE account_id = ?');
    $row->execute([$accountId]);

    return $row->fetch() ?: [];
}

/** Create the row if missing and set the given columns. Column names are fixed by the callers. */
function stripe_account_set(string $accountId, array $columns): void
{
    $pdo = stripe_db();
    $pdo->prepare('INSERT OR IGNORE INTO stripe_customers (account_id) VALUES (?)')->execute([$accountId]);
    if (!$columns) return;
    $sets = [];
    foreach (array_keys($columns) as $name) $sets[] = $name . ' = :' . $name;
    $pdo->prepare('UPDATE stripe_customers SET ' . implode(', ', $sets)
        . ', updated_at = CURRENT_TIMESTAMP WHERE account_id = :account_id')
        ->execute($columns + ['account_id' => $accountId]);
}

function stripe_is_duplicate_intent(PDOException $exception): bool
{
    $message = $exception->getMessage();

    return str_contains($message, 'transactions_stripe_intent_id_unique')
        || str_contains($message, 'UNIQUE constraint failed: transactions.stripe_intent_id');
}

/** @return int|null the new transaction id, or null when this intent is already recorded. */
function transaction_record(string $accountId, string $kind, int $amountCents, string $currency,
    ?string $intentId, string $status = 'pending'): ?int
{
    try {
        stripe_db()->prepare('INSERT INTO transactions (account_id, kind, amount_cents, currency, stripe_intent_id, status)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([$accountId, $kind, $amountCents, strtolower($currency), $intentId, $status]);

        return (int) stripe_db()->lastInsertId();
    } catch (PDOException $exception) {
        if (stripe_is_duplicate_intent($exception)) return null;
        throw $exception;
    }
}

/** @return array<string, mixed>|null */
function transaction_by_intent(string $intentId): ?array
{
    $row = stripe_db()->prepare('SELECT * FROM transactions WHERE stripe_intent_id = ?');
    $row->execute([$intentId]);

    return $row->fetch() ?: null;
}

/** Move pending or failed money to succeeded. A refunded or disputed row is never moved back. */
function transaction_mark_succeeded(string $intentId): bool
{
    $statement = stripe_db()->prepare("UPDATE transactions SET status = 'succeeded'
        WHERE stripe_intent_id = ? AND status IN ('pending', 'failed')");
    $statement->execute([$intentId]);

    return $statement->rowCount() === 1;
}

function transaction_mark_failed(string $intentId): bool
{
    $statement = stripe_db()->prepare("UPDATE transactions SET status = 'failed'
        WHERE stripe_intent_id = ? AND status = 'pending'");
    $statement->execute([$intentId]);

    return $statement->rowCount() === 1;
}
