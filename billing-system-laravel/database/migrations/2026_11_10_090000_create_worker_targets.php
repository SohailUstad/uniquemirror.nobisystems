<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('worker_daily_targets', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->foreignId('worker_id')->constrained();
            $t->date('effective_date'); $t->decimal('target_square_feet', 19, 4); $t->timestamps();
            $t->unique(['worker_id', 'effective_date'], 'uk_worker_target_date'); $t->index(['user_id', 'worker_id', 'effective_date'], 'idx_worker_target_lookup');
        });
        Schema::create('worker_daily_target_overrides', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->foreignId('worker_id')->constrained();
            $t->date('target_date'); $t->decimal('target_square_feet', 19, 4); $t->timestamps();
            $t->unique(['worker_id', 'target_date'], 'uk_worker_override_date'); $t->index(['user_id', 'worker_id', 'target_date'], 'idx_worker_override_lookup');
        });
        Schema::create('user_working_day_settings', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->unsignedTinyInteger('working_day_mask')->default(127);
            $t->timestamps(); $t->unique('user_id', 'uk_user_workdays_user');
        });
        Schema::create('worker_working_day_settings', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->foreignId('worker_id')->constrained();
            $t->unsignedTinyInteger('working_day_mask')->default(127); $t->timestamps();
            $t->unique('worker_id', 'uk_worker_workdays_worker'); $t->index(['user_id', 'worker_id'], 'idx_worker_workdays_user');
        });
        Schema::create('worker_target_resets', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->foreignId('worker_id')->constrained();
            $t->date('reset_date'); $t->dateTime('created_at')->useCurrent();
            $t->unique(['worker_id', 'reset_date'], 'uk_worker_reset_date'); $t->index(['user_id', 'worker_id', 'reset_date'], 'idx_worker_reset_lookup');
        });
    }

    public function down(): void
    {
        foreach (['worker_target_resets', 'worker_working_day_settings', 'user_working_day_settings', 'worker_daily_target_overrides', 'worker_daily_targets'] as $table) Schema::dropIfExists($table);
    }
};
