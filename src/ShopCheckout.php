<?php

declare(strict_types=1);

namespace Playground;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\PayxyCheckout;
use Payxy\Payxy;
use Payxy\Support\Amount;

/**
 * The integrator's checkout class: settings as properties, amounts from our own orders.
 */
final class ShopCheckout extends PayxyCheckout
{
    protected string $merchantName = 'Payxy Playground';

    protected ?string $buttonColor = '#17C96E';

    protected ?string $bodyColor = '#F4F6FA';

    public function __construct(Payxy $payxy, private readonly Orders $orders, private readonly string $returnUrl, CheckoutDisplay $display = CheckoutDisplay::Redirect)
    {
        parent::__construct($payxy);
        $this->display = $display;
        $this->footerText = 'Payxy Playground · ' . $payxy->getConfig()->environment->name;
    }

    protected function callbackUrl(): string
    {
        return $this->returnUrl;
    }

    protected function expectedAmount(string $transactionId): ?Amount
    {
        return $this->orders->amount($transactionId);
    }

    protected function feeAllowance(string $transactionId, Amount $expected): ?Amount
    {
        return FeePolicy::allowance($expected);
    }
}
