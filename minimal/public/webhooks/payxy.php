<?php

declare(strict_types=1);

use Payxy\Exception\PayxyException;
use Payxy\Exception\WebhookVerificationException;

require __DIR__ . '/../../bootstrap.php';

// 1-4. Raw body, timestamp, signature (HMAC-SHA512 with your sk_ secret), JSON.
try {
    $event = payxy()->webhooks()->constructEventFromGlobals();
} catch (WebhookVerificationException) {
    http_response_code(401);
    exit;
}

// 5-6. Record the event under a UNIQUE key so redeliveries are no-ops.
$db = new PDO((string) (getenv('DATABASE_DSN') ?: 'sqlite:' . sys_get_temp_dir() . '/payxy-webhooks.sqlite'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS payxy_webhook_events (idempotency_key VARCHAR(120) PRIMARY KEY, received_at TEXT NOT NULL)');

$db->beginTransaction();
$insert = $db->prepare('INSERT INTO payxy_webhook_events (idempotency_key, received_at) VALUES (?, ?) ON CONFLICT DO NOTHING');
$insert->execute([$event->idempotencyKey(), gmdate('c')]);
if ($insert->rowCount() === 0) {
    $db->rollBack();
    http_response_code(200);   // duplicate delivery: already processed
    exit;
}

// 7. Confirm with VerifyPayment before giving value, then apply the state change.
try {
    if ($event->isPaymentSuccess() && $event->getTransactionId() !== null) {
        $verification = payxy()->payments()->verify($event->getTransactionId());
        if ($verification->isPaid()) {
            // Compare $verification->getAmount() with your order, then mark it paid (idempotently).
        }
    }
    $db->commit();                  // 8.
} catch (PayxyException $e) {
    $db->rollBack();                // let Payxy redeliver
    error_log('Payxy webhook processing failed: ' . $e->getMessage());
    http_response_code(503);
    exit;
}

http_response_code(200);            // 9.
