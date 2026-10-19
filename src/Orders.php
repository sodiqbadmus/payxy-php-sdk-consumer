<?php

declare(strict_types=1);

namespace Playground;

use Payxy\Support\Amount;

/**
 * Stand-in for a merchant's orders table: a JSON file in the system temp directory.
 */
final class Orders
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/payxy-playground-orders.json';
    }

    public function create(string $transactionId, Amount $amount, string $email, string $flow): void
    {
        $all = $this->all();
        $all[$transactionId] = ['amount' => $amount->toMinorUnits(), 'email' => $email, 'flow' => $flow, 'status' => 'awaiting payment'];
        $this->write($all);
    }

    public function markStatus(string $transactionId, string $status): void
    {
        $all = $this->all();
        if (isset($all[$transactionId])) {
            $all[$transactionId]['status'] = $status;
            $this->write($all);
        }
    }

    public function amount(string $transactionId): ?Amount
    {
        $row = $this->all()[$transactionId] ?? null;

        return $row === null ? null : Amount::fromMinorUnits((int) $row['amount']);
    }

    /**
     * @return array<string, array{amount: int, email: string, flow: string, status: string}>
     */
    public function all(): array
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $data = $raw === false ? [] : json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        file_put_contents($this->path, json_encode($all, \JSON_PRETTY_PRINT), \LOCK_EX);
    }
}
