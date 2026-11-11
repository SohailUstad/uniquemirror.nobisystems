<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_customer_otps', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_number', 20);
            $table->string('otp_hash');
            $table->dateTime('expires_at');
            $table->boolean('verified')->default(false);
            $table->integer('attempts')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->index(['phone_number', 'verified', 'created_at'], 'public_customer_otp_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_customer_otps');
    }
};
