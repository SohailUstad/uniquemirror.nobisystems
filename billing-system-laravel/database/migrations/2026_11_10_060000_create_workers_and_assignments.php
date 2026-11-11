<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('name', 150);
            $t->string('phone_number', 30)->nullable();
            $t->string('code', 20)->unique();
            $t->string('password_hash', 100)->nullable();
            $t->boolean('must_change_password')->default(true);
            $t->dateTime('last_login_at')->nullable();
            $t->boolean('active')->default(true);
            $t->decimal('rate_per_square_foot', 19, 4)->nullable();
            $t->boolean('allow_multiple_active_orders')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'active']);
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->foreign('worker_id')->references('id')->on('workers');
            $t->index(['user_id', 'worker_id', 'assigned_at'], 'idx_orders_user_worker_assigned');
        });
        Schema::create('worker_order_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('worker_id')->constrained('workers');
            $t->foreignId('order_id')->constrained('orders');
            $t->dateTime('assigned_at');
            $t->dateTime('unassigned_at')->nullable();
            $t->boolean('current_assignment')->default(true);
            $t->decimal('applied_worker_rate', 19, 4)->nullable();
            $t->string('rate_source', 20)->nullable();
            $t->decimal('square_feet_snapshot', 19, 4)->nullable();
            $t->decimal('expected_amount', 19, 2)->nullable();
            $t->decimal('earned_amount', 19, 2)->nullable();
            $t->dateTime('earned_at')->nullable();
            $t->dateTime('earning_reversed_at')->nullable();
            $t->unsignedBigInteger('current_order_key')->nullable()->storedAs('case when `current_assignment` = 1 then `order_id` else null end');
            $t->unique('current_order_key', 'uk_worker_assignments_one_current_order');
            $t->index(['worker_id', 'current_assignment']);
            $t->index(['order_id', 'current_assignment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_order_assignments');
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropForeign(['worker_id']);
            $t->dropIndex('idx_orders_user_worker_assigned');
        });
        Schema::dropIfExists('workers');
    }
};
