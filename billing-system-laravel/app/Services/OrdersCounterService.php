<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Native, atomic implementation of the order status counter operations. */
class OrdersCounterService
{
    /** Insert a counter at one, or atomically add one to the existing row. */
    public function increment(string $status, int $userId): void
    {
        DB::table('orders_counter')->insertOrIgnore(['status'=>$status,'user_id'=>$userId,'count'=>0]);
        DB::table('orders_counter')->where('status',$status)->where('user_id',$userId)->increment('count');
    }

    /** Decrement an existing row without allowing its count to fall below zero. */
    public function decrement(string $status, int $userId): void
    {
        DB::table('orders_counter')->where('status',$status)->where('user_id',$userId)
            ->update(['count'=>DB::raw('CASE WHEN count > 0 THEN count - 1 ELSE 0 END')]);
    }

    /** Match the source bulk increment, including insert-if-missing behavior. */
    public function bulkIncrement(string $status, int $userId, int $amount): void
    {
        if ($amount < 0) throw new \InvalidArgumentException('count must not be negative');
        DB::table('orders_counter')->insertOrIgnore(['status'=>$status,'user_id'=>$userId,'count'=>0]);
        DB::table('orders_counter')->where('status',$status)->where('user_id',$userId)->increment('count',$amount);
    }

    /** Source bulk decrement subtracts the amount on an existing row. */
    public function bulkDecrement(string $status, int $userId, int $amount): void
    {
        if ($amount < 0) throw new \InvalidArgumentException('count must not be negative');
        $inserted=DB::table('orders_counter')->insertOrIgnore(['status'=>$status,'user_id'=>$userId,'count'=>$amount]);
        if ($inserted===0) {
            DB::table('orders_counter')->where('status',$status)->where('user_id',$userId)->decrement('count',$amount);
        }
    }

    /** Stable JSON-ready equivalent of the OrdersCounter entity list. */
    public function getCounts(int $userId): array
    {
        return DB::table('orders_counter')->where('user_id',$userId)->orderBy('id')->get()
            ->map(static fn(object $row): array => [
                'id'=>(int)$row->id,
                'status'=>$row->status,
                'userId'=>(int)$row->user_id,
                'count'=>(int)$row->count,
            ])->all();
    }
}
