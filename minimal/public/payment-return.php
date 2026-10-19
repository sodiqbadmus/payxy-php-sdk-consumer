<?php

declare(strict_types=1);

use Payxy\Exception\PayxyException;
use Payxy\Exception\RequestFailedException;
use Payxy\ResponseCode;
use Payxy\Support\Amount;

require __DIR__ . '/../bootstrap.php';

startSession();
$payment = $_SESSION['payxy_payment'] ?? null;
if (!is_array($payment)) {
    http_response_code(400);
    exit('No payment in progress.');
}

try {
    $verification = payxy()->payments()->verify($payment['transactionId']);
} catch (PayxyException $e) {
    if ($e instanceof RequestFailedException && $e->responseCode() === ResponseCode::BadRequest) {
        exit('The payment was not completed.');   // 04: Payxy has no payment attempt for this id
    }
    error_log('Payxy verify failed: ' . $e->getMessage());
    http_response_code(502);
    exit('We could not confirm the payment yet. Please refresh.');
}

$expected = Amount::fromString($payment['amount']);

if ($verification->isPaid() && $verification->amountMatches($expected, $payment['currency'])) {
    // Fulfil exactly once, e.g. UPDATE orders SET status = 'paid' WHERE id = ? AND status <> 'paid'
    echo 'Payment successful. Reference: ' . htmlspecialchars((string) $verification->getPaymentReference());
} elseif ($verification->isPending()) {
    echo 'Payment is processing. Refresh this page shortly.';
} else {
    echo 'Payment failed: ' . htmlspecialchars((string) $verification->getGatewayResponse());
}
