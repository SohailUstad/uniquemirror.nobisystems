<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_order_refs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->date('dashboard_date');
            $table->boolean('must_complete')->default(false);
            $table->integer('priority')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['user_id', 'order_id', 'dashboard_date'], 'uk_dashboard_order_date');
            $table->index(['user_id', 'dashboard_date', 'must_complete', 'priority'], 'idx_dashboard_order_schedule');
        });
        Schema::create('dashboard_daily_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('target_date');
            $table->integer('target_count');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['user_id', 'target_date'], 'uk_dashboard_target_date');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('dashboard_daily_targets');
        Schema::dropIfExists('dashboard_order_refs');
    }
};
