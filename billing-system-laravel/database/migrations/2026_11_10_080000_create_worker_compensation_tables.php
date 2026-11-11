<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('worker_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('worker_id')->constrained('workers');
            $t->decimal('current_balance', 19, 2)->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'worker_id'], 'uk_worker_accounts_user_worker');
        });
        Schema::create('worker_payout_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('worker_id')->constrained('workers');
            $t->decimal('requested_amount', 19, 2);
            $t->decimal('actual_amount', 19, 2)->nullable();
            $t->string('status', 20);
            $t->string('worker_note', 500)->nullable();
            $t->string('admin_note', 500)->nullable();
            $t->string('payment_mode', 40)->nullable();
            $t->string('reference_number', 100)->nullable();
            $t->dateTime('requested_at')->useCurrent();
            $t->dateTime('settled_at')->nullable();
            $t->dateTime('updated_at')->useCurrent();
            $t->unsignedBigInteger('pending_worker_key')->nullable()->storedAs("case when `status` = 'PENDING' then `worker_id` else null end");
            $t->unique('pending_worker_key', 'uk_worker_one_pending_payout');
            $t->index(['user_id', 'status', 'requested_at'], 'idx_worker_payout_user_status_date');
        });
        Schema::create('worker_ledger', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('worker_id')->constrained('workers');
            $t->string('entry_type', 40);
            $t->decimal('amount', 19, 2);
            $t->decimal('balance_after', 19, 2);
            $t->string('reference_type', 40)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('order_display_id', 50)->nullable();
            $t->string('order_description_snapshot', 1000)->nullable();
            $t->text('client_name_snapshot')->nullable();
            $t->unsignedBigInteger('order_id_snapshot')->nullable();
            $t->unsignedBigInteger('stage_id_snapshot')->nullable();
            $t->string('stage_name_snapshot', 150)->nullable();
            $t->decimal('square_feet_snapshot', 19, 4)->nullable();
            $t->decimal('rate_snapshot', 19, 4)->nullable();
            $t->string('rate_source', 30)->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->string('description', 500)->nullable();
            $t->dateTime('entry_date')->useCurrent();
            $t->dateTime('created_at')->useCurrent();
            $t->string('earning_reference_type', 40)->nullable()->storedAs("case when `entry_type` in ('ORDER_EARNING','ORDER_EARNING_REVERSAL') and `reference_type` in ('ASSIGNMENT','STAGE_ASSIGNMENT') then `reference_type` else null end");
            $t->unsignedBigInteger('earning_reference_id')->nullable()->storedAs("case when `entry_type` in ('ORDER_EARNING','ORDER_EARNING_REVERSAL') and `reference_type` in ('ASSIGNMENT','STAGE_ASSIGNMENT') then `reference_id` else null end");
            $t->string('earning_entry_type', 40)->nullable()->storedAs("case when `entry_type` in ('ORDER_EARNING','ORDER_EARNING_REVERSAL') and `reference_type` in ('ASSIGNMENT','STAGE_ASSIGNMENT') then `entry_type` else null end");
            $t->unique(['earning_reference_type', 'earning_reference_id', 'earning_entry_type'], 'uk_worker_ledger_earning');
            $t->index(['user_id', 'worker_id', 'entry_date', 'id'], 'idx_worker_ledger_user_worker_date');
            $t->index(['user_id', 'worker_id', 'completed_at'], 'idx_worker_ledger_completion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_ledger');
        Schema::dropIfExists('worker_payout_requests');
        Schema::dropIfExists('worker_accounts');
    }
};
