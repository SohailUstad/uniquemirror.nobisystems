<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class WorkerIdentityService
{
    public function __construct(private readonly WorkerCompensationService $compensation) {}

    public function ownerId(string $email): int
    {
        $id = DB::table('users')->where('email', $email)->value('id');
        if ($id === null) throw new InvalidArgumentException('User not found');
        return (int) $id;
    }

    public function worker(int $userId, int $workerId): object
    {
        $worker = DB::table('workers')->where('user_id', $userId)->where('id', $workerId)->where('active', true)->first();
        if (! $worker) throw new InvalidArgumentException('Worker not found');
        return $worker;
    }

    public function create(int $userId, array $input): object
    {
        $name = $this->name($input['name'] ?? null);
        $password = $input['password'] ?? null;
        if ($password !== null && $password !== '') $this->validPassword($password);
        $rate = $this->rate($input['ratePerSquareFoot'] ?? null);
        return DB::transaction(function () use ($userId, $input, $name, $password, $rate) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $suffixes = range(0, 99);
            shuffle($suffixes);
            $code = null;
            foreach ($suffixes as $suffix) {
                $candidate = $userId.'-'.sprintf('%02d', $suffix);
                if (! DB::table('workers')->where('code', $candidate)->exists()) { $code = $candidate; break; }
            }
            if ($code === null) throw new InvalidArgumentException('No worker codes left for this business. Delete an unused worker first.');
            $now = now();
            $id = DB::table('workers')->insertGetId([
                'user_id' => $userId, 'name' => $name, 'phone_number' => $this->phone($input['phoneNumber'] ?? null),
                'code' => $code, 'password_hash' => Hash::make($password ?: '0000'), 'must_change_password' => true,
                'active' => true, 'rate_per_square_foot' => $rate,
                'allow_multiple_active_orders' => ($input['allowMultipleActiveOrders'] ?? null) === true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $this->worker($userId, $id);
        });
    }

    public function update(int $userId, int $workerId, array $input): object
    {
        $this->worker($userId, $workerId);
        $values = [];
        if (array_key_exists('name', $input) && $input['name'] !== null) $values['name'] = $this->name($input['name']);
        if (array_key_exists('phoneNumber', $input) && $input['phoneNumber'] !== null) $values['phone_number'] = $this->phone($input['phoneNumber']);
        if (array_key_exists('ratePerSquareFoot', $input)) $values['rate_per_square_foot'] = $this->rate($input['ratePerSquareFoot']);
        if (array_key_exists('allowMultipleActiveOrders', $input)) $values['allow_multiple_active_orders'] = $input['allowMultipleActiveOrders'] === true;
        if ($values) DB::table('workers')->where('id', $workerId)->update($values + ['updated_at' => now()]);
        return $this->worker($userId, $workerId);
    }

    public function setPassword(int $userId, int $workerId, mixed $password): void
    {
        $this->validPassword($password);
        $this->worker($userId, $workerId);
        DB::table('workers')->where('id', $workerId)->update(['password_hash' => Hash::make($password), 'must_change_password' => true, 'updated_at' => now()]);
    }

    public function assign(int $userId, int $workerId, mixed $orderIds): array
    {
        if (! is_array($orderIds) || $orderIds === []) throw new InvalidArgumentException('orderIds is required');
        foreach ($orderIds as $id) if (! is_int($id) && ! is_float($id)) throw new InvalidArgumentException('orderIds must contain numeric order ids');
        return DB::transaction(function () use ($userId, $workerId, $orderIds) {
            $worker = $this->worker($userId, $workerId);
            $out = [];
            foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
                $order = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
                if (! $order) throw new InvalidArgumentException('Order not found: '.$orderId);
                if ($order->billed) throw new InvalidArgumentException('Billed orders cannot be assigned');
                if (strcasecmp((string) $order->currentStage, 'Completed') === 0) throw new InvalidArgumentException('Move the order out of Completed before assigning it');
                $current = DB::table('worker_order_assignments')->where('order_id', $orderId)->where('current_assignment', true)->lockForUpdate()->first();
                if ($current) {
                    if ($current->earned_at && ! $current->earning_reversed_at) throw new InvalidArgumentException('Reverse the existing worker earning before reassigning this order');
                    DB::table('worker_order_assignments')->where('id', $current->id)->update(['current_assignment' => false, 'unassigned_at' => now()]);
                }
                $now = now();
                DB::table('orders')->where('id', $orderId)->update(['worker_id' => $workerId, 'assigned_at' => $now]);
                $assignmentId = DB::table('worker_order_assignments')->insertGetId([
                    'user_id' => $userId, 'worker_id' => $workerId, 'order_id' => $orderId,
                    'assigned_at' => $now, 'current_assignment' => true,
                ]);
                $this->compensation->snapshotAssignment($userId, $assignmentId);
                $out[] = DB::table('worker_order_assignments')->where('id', $assignmentId)->first();
            }
            return [$worker, $out];
        });
    }

    private function name(mixed $name): string
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '') throw new InvalidArgumentException('Worker name is required');
        return $name;
    }
    private function phone(mixed $phone): ?string { $value = trim((string) ($phone ?? '')); return $value === '' ? null : $value; }
    private function rate(mixed $rate): ?string
    {
        if ($rate === null || $rate === '') return null;
        if (! is_numeric($rate)) throw new InvalidArgumentException('Invalid worker rate');
        if ((float) $rate < 0) throw new InvalidArgumentException('Worker rate cannot be negative');
        return (string) $rate;
    }
    private function validPassword(mixed $password): void
    {
        if (! is_string($password) || mb_strlen($password) < 6) throw new InvalidArgumentException('Password must be at least 6 characters');
    }
}
