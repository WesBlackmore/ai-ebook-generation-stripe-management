<?php

declare(strict_types=1);

/** Plain require entry point: `require 'src/autoload.php';` loads the whole package. */

require_once __DIR__ . '/stripe_client.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/subscriptions.php';
require_once __DIR__ . '/refunds.php';
require_once __DIR__ . '/webhooks.php';
