<?php

declare(strict_types=1);

use Payxy\Exception\PayxyException;
use Payxy\Exception\RequestFailedException;
use Payxy\ResponseCode;
use Payxy\Support\Amount;

require __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

startSession();
$payment = $_SESSION['payxy_payment'] ?? null;   // only the payment this browser started
if (!is_array($payment)) {
    http_response_code(404);
    exit(json_encode(['status' => 'none']));
}

try {
    $verification = payxy()->payments()->verify($payment['transactionId']);
} catch (PayxyException $e) {
    if ($e instanceof RequestFailedException && $e->responseCode() === ResponseCode::BadRequest) {
        exit(json_encode(['status' => 'not_attempted']));   // 04: no payment attempt yet
    }
    error_log('Payxy verify failed: ' . $e->getMessage());
    http_response_code(502);
    exit(json_encode(['status' => 'unknown']));
}

$paid = $verification->isPaid()
    && $verification->amountMatches(Amount::fromString($payment['amount']), $payment['currency']);

echo json_encode([
    'status' => match (true) {
        $paid => 'paid',
        $verification->isPending() => 'pending',
        default => 'failed',
    },
    'reference' => $verification->getPaymentReference(),
    'message' => $verification->getGatewayResponse(),
]);
