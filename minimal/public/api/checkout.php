<?php

declare(strict_types=1);

use Payxy\Exception\PayxyException;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;
use Payxy\Support\Reference;

require __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

// JSON-only POST: a cross-site HTML form cannot send this content type.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    http_response_code(405);
    exit(json_encode(['error' => 'POST application/json only']));
}

startSession();
$order = currentOrder();
$transactionId = Reference::generate('ORD');
$_SESSION['payxy_payment'] = ['transactionId' => $transactionId] + $order;

try {
    $payment = payxy()->payments()->initialize(new InitializePaymentRequest(
        email: $order['email'],
        amount: Amount::fromString($order['amount']),
        transactionId: $transactionId,
        callbackUrl: appUrl('/checkout.html?returned=1'),
        currency: $order['currency'],
        productDescription: $order['description'],
    ));
} catch (PayxyException $e) {
    error_log('Payxy initialize failed: ' . $e->getMessage());
    http_response_code(502);
    exit(json_encode(['error' => 'Payment could not be started.']));
}

// Safe for the browser: no keys, only what the hosted checkout needs.
echo json_encode([
    'transactionId' => $transactionId,
    'accessCode' => $payment->getAccessCode(),
    'paymentUrl' => $payment->getPaymentUrl(),
    'environment' => payxy()->getConfig()->environment->browserSdkEnvironment(),
], JSON_UNESCAPED_SLASHES);
