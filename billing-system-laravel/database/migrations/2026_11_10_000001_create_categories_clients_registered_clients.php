<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $mysqlFamily = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        Schema::create('registered_clients', function (Blueprint $table) use ($mysqlFamily) {
            $table->id();
            $email = $table->string('email', 160);
            if ($mysqlFamily) $email->collation('utf8mb4_bin');
            $email->unique('uk_registered_clients_email');
            $table->string('phone_number', 10)->unique('uk_registered_clients_phone');
            $table->string('password_hash');
            $table->boolean('email_verified')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'active'], 'idx_categories_user_active');
        });

        Schema::create('clients', function (Blueprint $table) use ($mysqlFamily) {
            $table->id();
            // utf8mb4_bin preserves PostgreSQL's case-sensitive equality in the source query.
            $clientName = $table->string('clientName', 100);
            if ($mysqlFamily) $clientName->collation('utf8mb4_bin');
            $table->string('phoneNumber', 15);
            $table->string('address', 255);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('registered_client_id')->nullable()->constrained('registered_clients')->nullOnDelete();
            $table->timestamps();
            $table->unique(['clientName', 'user_id'], 'uk_clients_name_user');
            $table->index('registered_client_id', 'idx_clients_registered_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('registered_clients');
    }
};
