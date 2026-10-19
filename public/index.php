<?php

declare(strict_types=1);

/**
 * Payxy Playground: a small merchant app consuming payxy/payxy-php-sdk from Packagist.
 *
 *   php -S localhost:8081 -t public public/index.php
 *
 * Flows:
 *   /backend        Backend (API only): our PHP calls initialize() and verify() directly.
 *   /frontend       Frontend (JS app): the browser talks to our JSON API built on PayxyCheckout.
 *   /frontend/sdk   Frontend (SDK-rendered): PayxyCheckout renders the checkout page for us.
 */

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\CheckoutOutcome;
use Payxy\Checkout\CheckoutRequest;
use Payxy\Exception\PayxyException;
use Payxy\Exception\RequestFailedException;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payments\Response\VerifyPaymentResponse;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\ResponseCode;
use Payxy\Support\Amount;
use Payxy\Support\Reference;
use Playground\Orders;
use Playground\ShopCheckout;
use Playground\View;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // static files under public/ are served by the built-in server
}

require __DIR__ . '/../vendor/autoload.php';
loadEnv(__DIR__ . '/../.env');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

$baseUrl = rtrim((string) (getenv('PLAYGROUND_URL') ?: 'http://localhost:8081'), '/');
$payxy = new Payxy(new PayxyConfig(
    publicKey: (string) getenv('PAYXY_PUBLIC_KEY'),
    webhookSecret: getenv('PAYXY_WEBHOOK_SECRET') ?: null,
));
$orders = new Orders();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    match (true) {
        $path === '/' => home($payxy),

        // Backend (API only)
        $path === '/backend' => orderForm('Backend flow (API only)', '/backend/pay', 'Our PHP server calls <code>payments()->initialize()</code>, redirects you to Payxy, then calls <code>payments()->verify()</code> when you come back.'),
        $path === '/backend/pay' && $method === 'POST' => backendPay($payxy, $orders, $baseUrl),
        $path === '/backend/return' => backendReturn($payxy, $orders),

        // Frontend (JavaScript app + JSON API)
        $path === '/frontend' => jsApp('checkout'),
        $path === '/frontend/return' => jsApp('return'),
        $path === '/api/checkout' && $method === 'POST' => apiCheckout($payxy, $orders, $baseUrl),
        str_starts_with($path, '/api/payments/') => apiConfirm($payxy, $orders, $baseUrl, rawurldecode(substr($path, \strlen('/api/payments/')))),
        $path === '/assets/payxy-overlay.js' => vendorAsset('payxy-1.0.10.umd.js', 'application/javascript'),

        // Frontend (SDK renders the checkout page)
        $path === '/frontend/sdk' && $method === 'GET' => orderForm('Frontend flow (SDK-rendered page)', '/frontend/sdk', '<code>PayxyCheckout::renderPage()</code> builds the checkout page; the result comes from <code>PayxyCheckout::confirm()</code>.'),
        $path === '/frontend/sdk' && $method === 'POST' => sdkPage($payxy, $orders, $baseUrl),
        $path === '/frontend/sdk/return' => sdkReturn($payxy, $orders, $baseUrl),

        // Webhooks (needs PAYXY_WEBHOOK_SECRET and a public HTTPS URL to actually receive calls)
        $path === '/api/webhooks/payxy' && $method === 'POST' => webhook($payxy, $orders, $baseUrl),

        default => notFound(),
    };
} catch (PayxyException $e) {
    if (str_starts_with($path, '/api/')) {
        json(['error' => $e::class, 'message' => $e->getMessage()], 502);
    } else {
        echo View::page('Payxy error', '<div class="alert">' . View::e($e::class) . '<br>' . View::e($e->getMessage()) . '</div><p><a href="/">Back</a></p>');
    }
}

// ---------------------------------------------------------------- pages

function home(Payxy $payxy): void
{
    $env = $payxy->getConfig()->environment->name;
    echo View::page('Choose a flow', <<<HTML
        <p class="muted">Environment <b>{$env}</b> · {$payxy->getConfig()->baseUrl} · SDK installed with <code>composer require payxy/payxy-php-sdk</code></p>
        <div class="grid">
          <a class="card link" href="/backend"><b>Backend (API only)</b><span>PHP form → <code>initialize()</code> → redirect → <code>verify()</code></span></a>
          <a class="card link" href="/frontend"><b>Frontend: JavaScript app</b><span>Browser → our JSON API (<code>start()</code> / <code>confirm()</code>) → Payxy</span></a>
          <a class="card link" href="/frontend/sdk"><b>Frontend: SDK-rendered page</b><span><code>PayxyCheckout</code> renders the Pay page for us</span></a>
        </div>
        HTML);
}

function orderForm(string $title, string $action, string $explain): void
{
    echo View::page($title, '<p class="muted">' . $explain . '</p>'
        . '<form method="post" action="' . View::e($action) . '" class="card">'
        . '<label>Amount (NGN)<input name="amount" value="100.00" inputmode="decimal" required></label>'
        . '<label>Email<input name="email" type="email" value="playground@example.com" required></label>'
        . '<button type="submit">Pay with Payxy</button></form>');
}

/** Backend: initialize with the plain client, remember the id in OUR session, redirect. */
function backendPay(Payxy $payxy, Orders $orders, string $baseUrl): void
{
    $amount = Amount::fromString(trim((string) ($_POST['amount'] ?? '')));
    $email = (string) ($_POST['email'] ?? '');
    $transactionId = Reference::generate('BE');

    $orders->create($transactionId, $amount, $email, 'backend');
    $_SESSION['backend_transaction_id'] = $transactionId;

    $response = $payxy->payments()->initialize(new InitializePaymentRequest(
        email: $email,
        amount: $amount,
        transactionId: $transactionId,
        callbackUrl: $baseUrl . '/backend/return',
        metadata: ['flow' => 'backend'],
        productDescription: 'Playground order',
    ));

    header('Location: ' . $response->getPaymentUrl(), true, 303);
}

/** Backend: verify with the id from OUR session, never from the redirect query. */
function backendReturn(Payxy $payxy, Orders $orders): void
{
    $transactionId = $_SESSION['backend_transaction_id'] ?? null;
    $transactionId = recheckId($orders) ?? $transactionId;
    if (!is_string($transactionId) || !isset($orders->all()[$transactionId])) {
        echo View::page('Backend result', '<p>No backend order in this session. <a href="/backend">Start one</a>.</p>');
        return;
    }

    try {
        $verification = $payxy->payments()->verify($transactionId);
    } catch (RequestFailedException $e) {
        if ($e->responseCode() !== ResponseCode::BadRequest) {
            throw $e;
        }
        $orders->markStatus($transactionId, 'no payment attempted');
        echo View::page('Backend result', View::table([
            'Result' => View::badge('No payment attempted yet', 'bad'),
            'Transaction ID' => View::e($transactionId),
            'Payxy said' => View::e($e->getMessage()),
        ]) . links('/backend/return?recheck=' . rawurlencode($transactionId), '/backend'));
        return;
    }

    $expected = $orders->amount($transactionId);
    $paid = $verification->isPaid() && $expected !== null && $verification->amountMatches($expected, 'NGN');
    [$label, $tone] = match (true) {
        $paid => ['Paid: fulfil the order', 'ok'],
        $verification->isPaid() => ['Paid, but amount mismatch: do not fulfil', 'bad'],
        $verification->isPending() => ['Pending: check again later', 'warn'],
        default => ['Not paid', 'bad'],
    };
    $orders->markStatus($transactionId, $label);

    echo View::page('Backend result', View::table(['Result' => View::badge($label, $tone)] + verificationRows($transactionId, $expected, $verification))
        . links('/backend/return?recheck=' . rawurlencode($transactionId), '/backend'));
}

/** Frontend (SDK-rendered): start a session, keep the id, let the SDK render the page. */
function sdkPage(Payxy $payxy, Orders $orders, string $baseUrl): void
{
    $checkout = new ShopCheckout($payxy, $orders, $baseUrl . '/frontend/sdk/return', CheckoutDisplay::Popup);
    $amount = Amount::fromString(trim((string) ($_POST['amount'] ?? '')));
    $email = (string) ($_POST['email'] ?? '');

    $session = $checkout->start(new CheckoutRequest(email: $email, amount: $amount, metadata: ['flow' => 'sdk-page'], productDescription: 'Playground order'));
    $orders->create($session->transactionId, $amount, $email, 'sdk-page');
    $_SESSION['sdk_transaction_id'] = $session->transactionId;

    echo $checkout->renderPage($session); // popup on https, redirect fallback on http
}

function sdkReturn(Payxy $payxy, Orders $orders, string $baseUrl): void
{
    $transactionId = $_SESSION['sdk_transaction_id'] ?? null;
    $transactionId = recheckId($orders) ?? $transactionId;
    if (!is_string($transactionId) || !isset($orders->all()[$transactionId])) {
        echo View::page('SDK page result', '<p>No order in this session. <a href="/frontend/sdk">Start one</a>.</p>');
        return;
    }

    $result = (new ShopCheckout($payxy, $orders, $baseUrl . '/frontend/sdk/return'))->confirm($transactionId);
    $orders->markStatus($transactionId, $result->outcome->value);

    $tone = match ($result->outcome) {
        CheckoutOutcome::Successful => 'ok',
        CheckoutOutcome::Pending => 'warn',
        default => 'bad',
    };
    $rows = ['Outcome' => View::badge($result->outcome->name, $tone)]
        + verificationRows($transactionId, $orders->amount($transactionId), $result->verification);

    echo View::page('SDK page result', View::table($rows) . links('/frontend/sdk/return?recheck=' . rawurlencode($transactionId), '/frontend/sdk'));
}

/** Frontend (JS app): a static shell; all logic is in public/assets/app.js. */
function jsApp(string $mode): void
{
    $head = '<script src="/assets/payxy-overlay.js" defer></script><script src="/assets/app.js" defer></script>';
    $body = $mode === 'checkout'
        ? '<p class="muted">This page is plain JavaScript. It calls <code>POST /api/checkout</code> (our backend runs <code>PayxyCheckout::start()</code>), '
            . 'then opens Payxy: the official overlay on HTTPS, a redirect on plain http. The result comes from <code>GET /api/payments/{id}</code> (<code>confirm()</code>).</p>'
            . '<form id="js-checkout" class="card"><label>Amount (NGN)<input name="amount" value="100.00" required></label>'
            . '<label>Email<input name="email" type="email" value="playground@example.com" required></label>'
            . '<button type="submit">Pay with Payxy</button><pre id="js-log" class="log" hidden></pre></form>'
        : '<div id="js-result" class="card"><p class="muted">Confirming payment with our backend…</p></div>';

    echo View::page($mode === 'checkout' ? 'Frontend flow (JavaScript app)' : 'JavaScript app result', $body, $head);
}

// ---------------------------------------------------------------- JSON API used by the JS app

function apiCheckout(Payxy $payxy, Orders $orders, string $baseUrl): void
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $amount = Amount::fromString(trim((string) ($input['amount'] ?? '')));
    $email = (string) ($input['email'] ?? '');

    $checkout = new ShopCheckout($payxy, $orders, $baseUrl . '/frontend/return');
    $session = $checkout->start(new CheckoutRequest(email: $email, amount: $amount, metadata: ['flow' => 'js-app'], productDescription: 'Playground order'));
    $orders->create($session->transactionId, $amount, $email, 'js-app');
    $_SESSION['js_transactions'][] = $session->transactionId;
    $_SESSION['js_transaction_id'] = $session->transactionId;

    json($session->toArray()); // no secrets: id, accessCode, paymentUrl, amount, currency, environment
}

function apiConfirm(Payxy $payxy, Orders $orders, string $baseUrl, string $transactionId): void
{
    if ($transactionId === 'current') {
        $transactionId = (string) ($_SESSION['js_transaction_id'] ?? '');
    }
    // A browser may only ask about transactions it started in this session.
    if (!in_array($transactionId, $_SESSION['js_transactions'] ?? [], true)) {
        json(['error' => 'unknown transaction for this session'], 404);
        return;
    }

    $result = (new ShopCheckout($payxy, $orders, $baseUrl . '/frontend/return'))->confirm($transactionId);
    $orders->markStatus($transactionId, $result->outcome->value);
    $v = $result->verification;

    json([
        'transactionId' => $transactionId,
        'outcome' => $result->outcome->value,
        'orderAmount' => (string) $orders->amount($transactionId),
        'payxy' => $v === null ? null : [
            'responseCode' => $v->getResponseCode(),
            'status' => $v->getStatus(),
            'isPaid' => $v->isPaid(),
            'isPending' => $v->isPending(),
            'amount' => (string) $v->getAmount(),
            'customerPaid' => (string) $v->getCustomerPaid(),
            'totalCharges' => (string) $v->getTotalCharges(),
            'paymentType' => $v->getPaymentType(),
            'gatewayResponse' => $v->getGatewayResponse(),
            'paymentReference' => $v->getPaymentReference(),
            'card' => trim(($v->getCardType() ?? '') . ' ' . ($v->getMaskedCardPan() ?? '')) ?: null,
        ],
    ]);
}

function webhook(Payxy $payxy, Orders $orders, string $baseUrl): void
{
    if ($payxy->getConfig()->webhookSecret === null) {
        json(['error' => 'Set PAYXY_WEBHOOK_SECRET (sk_test_...) in playground/.env to verify webhooks.'], 503);
        return;
    }
    try {
        $result = (new ShopCheckout($payxy, $orders, $baseUrl . '/frontend/return'))->handleWebhook(
            (string) file_get_contents('php://input'),
            $_SERVER['HTTP_X_PAYXY_SIGNATURE'] ?? null,
            $_SERVER['HTTP_X_PAYXY_TIMESTAMP'] ?? null,
        );
    } catch (WebhookVerificationException) {
        json(['error' => 'invalid signature'], 401);
        return;
    }
    $orders->markStatus($result->transactionId, $result->outcome->value . ' (webhook)');
    json(['ok' => true]);
}

// ---------------------------------------------------------------- helpers

/**
 * @return array<string, string>
 */
function verificationRows(string $transactionId, ?Amount $expected, ?VerifyPaymentResponse $v): array
{
    return [
        'Transaction ID' => View::e($transactionId),
        'Our order amount' => View::e($expected === null ? 'unknown' : (string) $expected),
        'responseCode' => View::e($v?->getResponseCode() ?? '04 (no record)'),
        'Payxy status' => View::e($v?->getStatus() ?? 'no payment attempted'),
        'isPaid()' => View::e(var_export($v?->isPaid() ?? false, true)),
        'Amount (data.amount)' => View::e((string) ($v?->getAmount() ?? '—')),
        'Customer paid' => View::e((string) ($v?->getCustomerPaid() ?? '—')),
        'Total charges' => View::e((string) ($v?->getTotalCharges() ?? '—')),
        'Payment type' => View::e($v?->getPaymentType() ?? '—'),
        'Gateway response' => View::e($v?->getGatewayResponse() ?? '—'),
        'Payment reference' => View::e($v?->getPaymentReference() ?? '—'),
        'Card' => View::e(trim(($v?->getCardType() ?? '') . ' ' . ($v?->getMaskedCardPan() ?? '')) ?: '—'),
    ];
}

/** The explicit "Re-check status" link names one of our own orders. */
function recheckId(Orders $orders): ?string
{
    $id = $_GET['recheck'] ?? null;

    return is_string($id) && isset($orders->all()[$id]) ? $id : null;
}

function links(string $recheck, string $again): string
{
    return '<p class="actions"><a href="' . View::e($recheck) . '">Re-check status</a> · <a href="' . View::e($again) . '">New order</a> · <a href="/">All flows</a></p>';
}

/** Serves the Payxy browser SDK that ships inside the Composer package. */
function vendorAsset(string $file, string $type): void
{
    header('Content-Type: ' . $type . '; charset=utf-8');
    readfile(__DIR__ . '/../vendor/payxy/payxy-php-sdk/resources/vendor/' . $file);
}

/**
 * @param array<string, mixed> $data
 */
function json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function notFound(): void
{
    http_response_code(404);
    echo View::page('Not found', '<p><a href="/">All flows</a></p>');
}

function loadEnv(string $file): void
{
    foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m) === 1 && getenv($m[1]) === false) {
            putenv($m[1] . '=' . $m[2]);
        }
    }
}
