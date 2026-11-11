<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Transactional balance cache and append-only account ledger. */
class ClientBalanceService
{
    private function money(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HALF_UP);
    }

    private function balance(int $userId, int $clientId, bool $create = true): object
    {
        $row = DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->first();
        if ($row || !$create) return $row ?? (object) ['currentBalance' => '0.00', 'currentOrdersTotalAmount' => '0.00'];
        $now = now()->format('Y-m-d H:i:s');
        DB::table('client_balances')->insertOrIgnore([
            'user_id' => $userId, 'client_id' => $clientId, 'currentBalance' => '0.00',
            'currentOrdersTotalAmount' => '0.00', 'createdAt' => $now, 'updatedAt' => $now,
        ]);
        return DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->first();
    }

    private function dto(int $clientId, object $balance): array
    {
        return ['customerId' => $clientId, 'currentBalance' => (float) $this->money($balance->currentBalance)->__toString(),
            'currentOrdersTotalAmount' => (float) $this->money($balance->currentOrdersTotalAmount)->__toString()];
    }

    private function changeBalance(int $userId, int $clientId, BigDecimal $delta): object
    {
        $balance = $this->balance($userId, $clientId);
        $next = $this->money($balance->currentBalance)->plus($delta)->toScale(2, RoundingMode::HALF_UP);
        DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)->update([
            'currentBalance' => (string) $next, 'updatedAt' => now()->format('Y-m-d H:i:s'),
        ]);
        $balance->currentBalance = (string) $next;
        return $balance;
    }

    private function ledger(int $userId, int $clientId, string $type, BigDecimal $amount, object $balance, ?string $refType, ?int $refId, ?string $note): int
    {
        return (int) DB::table('client_balance_ledger')->insertGetId([
            'user_id' => $userId, 'client_id' => $clientId, 'entry_type' => $type,
            'amount' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
            'balance_after' => (string) $this->money($balance->currentBalance),
            'reference_type' => $refType, 'reference_id' => $refId, 'note' => $note,
            'entry_date' => now()->format('Y-m-d H:i:s'), 'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function getBalance(int $userId, int $clientId): array
    {
        return DB::transaction(fn () => $this->dto($clientId, $this->balance($userId, $clientId)));
    }

    public function getExistingBalanceOrZero(int $userId, int $clientId): array
    {
        return $this->dto($clientId, $this->balance($userId, $clientId, false));
    }

    public function addManualReceivable(int $userId, int $clientId, mixed $amount, ?string $note): array
    {
        $amount = $this->money($amount);
        if ($amount->compareTo(0) <= 0) throw new InvalidArgumentException('Receivable amount must be greater than zero');
        return DB::transaction(function () use ($userId, $clientId, $amount, $note) {
            $b = $this->changeBalance($userId, $clientId, $amount);
            $this->ledger($userId, $clientId, 'MANUAL_RECEIVABLE', $amount, $b, 'MANUAL_RECEIVABLE', null, $note);
            return $this->dto($clientId, $b);
        });
    }

    public function deleteManualReceivable(int $userId, int $clientId, int $receivableId, ?string $reason): array
    {
        return DB::transaction(function () use ($userId, $clientId, $receivableId, $reason) {
            $b = $this->balance($userId, $clientId);
            $entry = DB::table('client_balance_ledger')->where('id', $receivableId)->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->first();
            if (!$entry) throw new InvalidArgumentException('Manual receivable not found');
            if ($entry->entry_type !== 'MANUAL_RECEIVABLE') throw new InvalidArgumentException('Only manual receivables can be deleted with this endpoint');
            if ($entry->reversed_at || $entry->reversal_entry_id) throw new InvalidArgumentException('Manual receivable has already been deleted');
            $ledgerTotal = $this->money(DB::table('client_balance_ledger')->where('user_id', $userId)->where('client_id', $clientId)->sum('amount'));
            if ($this->money($b->currentBalance)->compareTo($ledgerTotal) !== 0) throw new InvalidArgumentException('Cannot delete receivable because account balance does not match ledger total');
            $negative = $this->money($entry->amount)->negated();
            $b = $this->changeBalance($userId, $clientId, $negative);
            $note = 'Deleted manual receivable #' . $entry->id;
            if ($reason !== null && trim($reason) !== '') $note .= ': ' . trim($reason);
            $reversal = $this->ledger($userId, $clientId, 'MANUAL_RECEIVABLE_DELETED', $negative, $b, 'MANUAL_RECEIVABLE', $receivableId, $note);
            DB::table('client_balance_ledger')->where('id', $receivableId)->update(['reversed_at' => now()->format('Y-m-d H:i:s'), 'reversal_entry_id' => $reversal]);
            return $this->dto($clientId, $b);
        });
    }

    public function addManualAdjustment(int $userId, int $clientId, mixed $amount, ?string $note): array
    {
        $amount = $this->money($amount);
        if ($amount->compareTo(0) === 0) throw new InvalidArgumentException('Adjustment amount cannot be zero');
        return DB::transaction(function () use ($userId, $clientId, $amount, $note) {
            $b = $this->changeBalance($userId, $clientId, $amount);
            $this->ledger($userId, $clientId, 'MANUAL_ADJUSTMENT', $amount, $b, 'MANUAL_ADJUSTMENT', null, $note);
            return $this->dto($clientId, $b);
        });
    }

    public function recordBillReceivable(int $userId, int $clientId, string $amount, int $billId, ?string $description = null): void
    {
        $amount = $this->money($amount);
        if ($amount->compareTo(0) <= 0) throw new InvalidArgumentException('Bill final amount must be greater than zero');
        DB::transaction(function () use ($userId, $clientId, $amount, $billId, $description) {
            $b = $this->changeBalance($userId, $clientId, $amount);
            $note = 'Bill #' . $billId . (($description !== null && trim($description) !== '') ? ': ' . trim($description) : '');
            $this->ledger($userId, $clientId, 'BILL_GENERATION', $amount, $b, 'BILL', $billId, $note);
        });
    }

    public function deleteBillLedgerEntry(int $userId, int $clientId, int $billId): void
    {
        DB::table('client_balance_ledger')->where('user_id', $userId)->where('client_id', $clientId)
            ->where('entry_type', 'BILL_GENERATION')->where('reference_type', 'BILL')->where('reference_id', $billId)->delete();
    }

    public function adjustCurrentOrdersTotal(int $userId, int $clientId, mixed $delta): void
    {
        $delta = $this->money($delta);
        if ($delta->compareTo(0) === 0) return;
        DB::transaction(function () use ($userId, $clientId, $delta) {
            $b = $this->balance($userId, $clientId);
            $next = $this->money($b->currentOrdersTotalAmount)->plus($delta);
            if ($next->compareTo(0) < 0) $next = $this->money(0);
            DB::table('client_balances')->where('user_id', $userId)->where('client_id', $clientId)->update(['currentOrdersTotalAmount' => (string) $next, 'updatedAt' => now()->format('Y-m-d H:i:s')]);
        });
    }

    public function increaseCurrentOrdersTotal(int $userId, int $clientId, mixed $amount): void { $this->adjustCurrentOrdersTotal($userId, $clientId, $amount); }
    public function decreaseCurrentOrdersTotal(int $userId, int $clientId, mixed $amount): void { $this->adjustCurrentOrdersTotal($userId, $clientId, $this->money($amount)->negated()); }

    public function recordPaymentReceived(int $userId, int $clientId, mixed $amount, int $paymentId, ?string $note): void
    {
        $amount = $this->money($amount);
        if ($amount->compareTo(0) <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero');
        DB::transaction(function () use ($userId, $clientId, $amount, $paymentId, $note) {
            $negative = $amount->negated(); $b = $this->changeBalance($userId, $clientId, $negative);
            $this->ledger($userId, $clientId, 'PAYMENT_RECEIVED', $negative, $b, 'PAYMENT', $paymentId, $note);
        });
    }

    public function recordPaymentUpdated(int $userId, int $clientId, mixed $oldAmount, mixed $newAmount, int $paymentId, ?string $note): void
    {
        $new = $this->money($newAmount); if ($new->compareTo(0) <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero');
        $delta = $this->money($oldAmount)->minus($new); if ($delta->compareTo(0) === 0) return;
        DB::transaction(function () use ($userId, $clientId, $delta, $paymentId, $note) { $b = $this->changeBalance($userId, $clientId, $delta); $this->ledger($userId, $clientId, 'PAYMENT_UPDATED', $delta, $b, 'PAYMENT', $paymentId, $note); });
    }

    public function recordPaymentDeleted(int $userId, int $clientId, mixed $amount, int $paymentId, ?string $note): void
    {
        $amount = $this->money($amount); if ($amount->compareTo(0) <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero');
        DB::transaction(function () use ($userId, $clientId, $amount, $paymentId, $note) { $b = $this->changeBalance($userId, $clientId, $amount); $this->ledger($userId, $clientId, 'PAYMENT_DELETED', $amount, $b, 'PAYMENT', $paymentId, $note); });
    }
}
