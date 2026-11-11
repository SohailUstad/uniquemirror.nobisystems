<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('date');
            $table->decimal('total_amount', 19, 2)->default(0);
            $table->decimal('subtotal_amount', 19, 2)->default(0);
            $table->decimal('discount_amount', 19, 2)->default(0);
            $table->string('description', 1000)->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('qr_code_path', 2048)->nullable();
            $table->index(['user_id', 'status'], 'idx_bills_user_status');
            $table->index(['client_id', 'status'], 'idx_bills_client_status');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->index(['bill_id', 'id'], 'idx_orders_bill_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_bill_id');
            $table->dropConstrainedForeignId('bill_id');
        });
        Schema::dropIfExists('bills');
    }
};
