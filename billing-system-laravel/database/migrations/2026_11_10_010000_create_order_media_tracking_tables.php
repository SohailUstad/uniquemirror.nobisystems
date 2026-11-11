<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('image_url', 1000);
            $table->string('label', 255)->nullable();
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at');
            $table->index(['order_id', 'display_order', 'id'], 'idx_order_images_display');
        });

        Schema::create('order_voice_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('storage_key', 512);
            $table->string('mime_type', 100)->nullable();
            $table->string('label', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->dateTime('created_at');
            $table->index(['order_id', 'created_at', 'id'], 'idx_voice_notes_order_time');
        });

        Schema::create('order_tracking', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->dateTime('tracking_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking');
        Schema::dropIfExists('order_voice_notes');
        Schema::dropIfExists('order_images');
    }
};
