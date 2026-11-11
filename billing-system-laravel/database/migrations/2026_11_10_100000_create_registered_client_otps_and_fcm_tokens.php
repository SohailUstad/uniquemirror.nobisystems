<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('registered_client_otps', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 160);
            $table->string('phone_number', 10);
            $table->string('otp_hash');
            $table->dateTime('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('verified')->default(false);
            $table->dateTime('created_at');
            $table->index(['email', 'phone_number', 'verified', 'created_at'], 'idx_registered_client_otps_email_phone');
        });
        Schema::create('registered_client_fcm_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registered_client_id')->constrained('registered_clients')->cascadeOnDelete();
            $table->string('token', 2048);
            $table->char('token_hash', 64)->unique('uk_registered_client_fcm_device_tokens_hash');
            $table->string('platform', 50)->nullable();
            $table->string('device_id')->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('last_seen_at');
            $table->dateTime('logged_out_at')->nullable();
            $table->timestamps();
            $table->index(['registered_client_id', 'active', 'logged_out_at'], 'idx_registered_client_fcm_tokens_active_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_client_fcm_device_tokens');
        Schema::dropIfExists('registered_client_otps');
    }
};
