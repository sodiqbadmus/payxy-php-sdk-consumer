<?php

declare(strict_types=1);

use Payxy\Exception\PayxyException;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;
use Payxy\Support\Reference;

require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

startSession();
$order = currentOrder();
$transactionId = Reference::generate('ORD');   // unique per attempt, 6-30 chars

// Persist the attempt BEFORE redirecting; the return page verifies this id, not the URL's.
$_SESSION['payxy_payment'] = ['transactionId' => $transactionId] + $order;

try {
    $payment = payxy()->payments()->initialize(new InitializePaymentRequest(
        email: $order['email'],
        amount: Amount::fromString($order['amount']),
        transactionId: $transactionId,
        callbackUrl: appUrl('/payment-return.php'),
        currency: $order['currency'],
        productDescription: $order['description'],
    ));
} catch (PayxyException $e) {
    error_log('Payxy initialize failed: ' . $e->getMessage());
    http_response_code(502);
    exit('We could not start the payment. Please try again.');
}

header('Location: ' . $payment->getPaymentUrl(), true, 303);
