<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_bill_sequence', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::create('client_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->decimal('currentBalance', 19, 2)->default(0);
            $table->decimal('currentOrdersTotalAmount', 19, 2)->default(0);
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
            $table->unique(['user_id', 'client_id'], 'uk_client_balances_user_client');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('description', 255);
            $table->double('height');
            $table->double('width');
            $table->unsignedInteger('quantity');
            $table->decimal('rate', 19, 4);
            $table->decimal('workerRatePerSquareFoot', 19, 4)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('worker_id')->nullable();
            $table->unsignedBigInteger('production_stage_flow_id')->nullable();
            $table->unsignedBigInteger('flow_progress_flow_id')->nullable();
            $table->unsignedBigInteger('last_completed_production_stage_id')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('orderId', 80)->unique();
            $table->string('imagePath', 2048)->nullable();
            $table->string('currentStage', 100)->nullable();
            $table->string('qrCodePath', 2048)->nullable();
            $table->string('deleiverdThorugh', 100)->nullable();
            $table->decimal('amount', 19, 2)->nullable();
            $table->decimal('baseAmount', 19, 2)->nullable();
            $table->decimal('componentAmount', 19, 2)->nullable();
            $table->decimal('totalSquareFeet', 19, 6)->nullable();
            $table->dateTime('date')->nullable();
            $table->string('note', 2000)->nullable();
            $table->dateTime('deadline')->nullable();
            $table->boolean('billed')->default(false);
            $table->timestamps();
            $table->unique(['client_id', 'sequence'], 'uk_client_sequence');
            $table->index(['user_id', 'id'], 'idx_orders_user_id');
            $table->index(['user_id', 'client_id', 'id'], 'idx_orders_user_client');
            $table->index(['user_id', 'currentStage', 'id'], 'idx_orders_user_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('client_balances');
        Schema::dropIfExists('client_bill_sequence');
    }
};
