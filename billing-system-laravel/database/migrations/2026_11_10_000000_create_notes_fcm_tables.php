<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('notes', function(Blueprint $t): void { $t->bigIncrements('id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('order_id'); $t->string('note',255); $t->dateTime('time_stamp'); $t->index(['order_id','user_id','id'],'idx_notes_order_user_id'); });
        Schema::create('fcm_device_tokens', function(Blueprint $t): void { $t->bigIncrements('id'); $t->foreignId('user_id')->constrained('users'); $t->string('token',2048); $t->char('token_hash',64)->unique('uk_fcm_device_tokens_hash'); $t->string('platform',50)->nullable(); $t->string('device_id',255)->nullable(); $t->string('user_agent',1000)->nullable(); $t->boolean('active')->default(true); $t->dateTime('last_seen_at'); $t->dateTime('logged_out_at')->nullable(); $t->dateTime('created_at'); $t->dateTime('updated_at'); $t->index(['user_id','active','logged_out_at'],'idx_fcm_active_user'); });
    }
    public function down(): void { Schema::dropIfExists('fcm_device_tokens'); Schema::dropIfExists('notes'); }
};
