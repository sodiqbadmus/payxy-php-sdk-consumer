<?php

declare(strict_types=1);

use Payxy\Payxy;
use Payxy\PayxyConfig;

require __DIR__ . '/vendor/autoload.php';

/**
 * One configured client per request. Credentials come from the environment, never from code.
 */
function payxy(): Payxy
{
    static $payxy = null;

    return $payxy ??= new Payxy(new PayxyConfig(
        publicKey: (string) getenv('PAYXY_PUBLIC_KEY'),
        webhookSecret: getenv('PAYXY_WEBHOOK_SECRET') ?: null,
    ));
}

/**
 * Absolute URL on this application, e.g. appUrl('/payment-return.php').
 */
function appUrl(string $path): string
{
    return rtrim((string) getenv('APP_URL'), '/') . $path;
}

function startSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => str_starts_with((string) getenv('APP_URL'), 'https://'),
        ]);
    }
}

/**
 * The order being paid. Replace with your cart/order lookup: amounts never come from the browser.
 *
 * @return array{amount: string, currency: string, email: string, description: string}
 */
function currentOrder(): array
{
    return ['amount' => '100.00', 'currency' => 'NGN', 'email' => 'customer@example.com', 'description' => 'Order #1001'];
}
