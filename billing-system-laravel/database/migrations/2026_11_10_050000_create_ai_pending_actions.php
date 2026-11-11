<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('action_type', 50);
            $table->string('status', 20);
            $table->decimal('amount', 19, 2);
            $table->date('payment_date');
            $table->string('payment_mode', 30);
            $table->string('reference_no', 255)->nullable();
            $table->string('note', 1000)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_payment_id')->nullable()->unique();
            $table->unsignedBigInteger('version')->default(0);
            $table->index(['user_id', 'status'], 'idx_ai_pending_user_status');
            $table->index('expires_at', 'idx_ai_pending_expires');
        });
    }

    public function down(): void { Schema::dropIfExists('ai_pending_actions'); }
};
