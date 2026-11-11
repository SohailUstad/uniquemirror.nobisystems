<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stages')) {
            return;
        }

        Schema::create('stages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 255)->nullable();
        });
    }

    public function down(): void
    {
        // Keep this migration additive: the table may have existed before up().
    }
};
