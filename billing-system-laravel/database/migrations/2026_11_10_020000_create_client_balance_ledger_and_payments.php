<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_balance_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('entry_type', 40);
            $table->decimal('amount', 19, 2);
            $table->decimal('balance_after', 19, 2);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 1000)->nullable();
            $table->dateTime('entry_date');
            $table->dateTime('created_at');
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('reversal_entry_id')->nullable();
            $table->index(['user_id', 'entry_date', 'id'], 'idx_client_ledger_user_date');
            $table->index(['user_id', 'client_id', 'entry_date', 'id'], 'idx_client_ledger_client_date');
            $table->index(['user_id', 'client_id', 'entry_type', 'reference_type', 'reference_id'], 'idx_client_ledger_reference');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->decimal('amount', 19, 2)->default(0);
            $table->decimal('allocated_amount', 19, 2)->default(0);
            $table->decimal('unallocated_amount', 19, 2)->default(0);
            $table->date('payment_date');
            $table->string('payment_mode', 30);
            $table->string('reference_no')->nullable();
            $table->string('note', 1000)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->boolean('deleted')->default(false);
            $table->index(['user_id', 'deleted', 'payment_date', 'created_at'], 'idx_payments_user_date');
            $table->index(['client_id', 'user_id', 'deleted', 'payment_date', 'created_at'], 'idx_payments_client_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('client_balance_ledger');
    }
};
