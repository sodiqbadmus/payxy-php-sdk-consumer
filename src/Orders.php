<?php

declare(strict_types=1);

namespace Playground;

use Payxy\Support\Amount;

/**
 * Stand-in for a merchant's orders table, kept in the encrypted State cookie so it works on
 * serverless hosts. A real shop stores orders in its database.
 */
final class Orders
{
    private const KEY = 'orders';
    private const KEEP = 10;

    public function create(string $transactionId, Amount $amount, string $email, string $flow): void
    {
        $all = $this->all();
        $all[$transactionId] = ['amount' => $amount->toMinorUnits(), 'email' => $email, 'flow' => $flow, 'status' => 'awaiting payment'];
        State::set(self::KEY, \array_slice($all, -self::KEEP, null, true));
    }

    public function markStatus(string $transactionId, string $status): void
    {
        $all = $this->all();
        if (isset($all[$transactionId])) {
            $all[$transactionId]['status'] = $status;
            State::set(self::KEY, $all);
        }
    }

    public function amount(string $transactionId): ?Amount
    {
        $row = $this->all()[$transactionId] ?? null;

        return \is_array($row) ? Amount::fromMinorUnits((int) $row['amount']) : null;
    }

    /**
     * @return array<string, array{amount: int, email: string, flow: string, status: string}>
     */
    public function all(): array
    {
        $all = State::get(self::KEY, []);

        return \is_array($all) ? $all : [];
    }
}
