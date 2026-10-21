<?php

declare(strict_types=1);

namespace Playground;

use Payxy\Support\Amount;

/**
 * The playground's fee-allowance business decision, from PAYXY_FEE_ALLOWANCE_PERCENT.
 * Unset (or 0) keeps the SDK's strict default: any surplus without a fee breakdown is a mismatch.
 */
final class FeePolicy
{
    public static function allowance(Amount $expected): ?Amount
    {
        $percent = getenv('PAYXY_FEE_ALLOWANCE_PERCENT');
        if (!\is_string($percent) || !is_numeric($percent)) {
            return null;
        }
        $basisPoints = (int) round((float) $percent * 100);
        if ($basisPoints <= 0) {
            return null;
        }

        // Integer maths on minor units, rounded up: 2% of 100.00 NGN = 2.00 NGN.
        return Amount::fromMinorUnits(intdiv($expected->toMinorUnits() * $basisPoints + 9_999, 10_000));
    }

    public static function describe(): string
    {
        $percent = getenv('PAYXY_FEE_ALLOWANCE_PERCENT');

        return \is_string($percent) && is_numeric($percent) && (float) $percent > 0 ? $percent . '% fee allowance' : 'strict (no fee allowance)';
    }
}
