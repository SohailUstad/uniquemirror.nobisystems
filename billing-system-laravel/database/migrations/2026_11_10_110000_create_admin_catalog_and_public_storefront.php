<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $t): void {
            $t->id(); $name = $t->string('name', 120); $slug = $t->string('slug', 160); $t->boolean('active')->default(true);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) { $name->collation('utf8mb4_bin'); $slug->collation('utf8mb4_bin'); }
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unique(['user_id', 'name']); $t->index(['user_id', 'name']); $t->index(['active', 'name']);
        });
        Schema::create('products', function (Blueprint $t): void {
            $t->id(); $t->string('name', 160); $slug = $t->string('slug', 180); $t->text('description')->nullable(); $t->text('keywords')->nullable();
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) $slug->collation('utf8mb4_bin');
            $t->decimal('base_price', 19, 2)->default(0); $t->boolean('active')->default(true); $t->boolean('featured')->default(false);
            $t->integer('display_order')->default(0); $t->timestamps();
            $t->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unique(['user_id', 'slug']); $t->index(['active', 'display_order', 'name']);
        });
        Schema::create('product_images', function (Blueprint $t): void {
            $t->id(); $t->string('image_url', 1000); $t->integer('display_order')->default(0); $t->boolean('primary_image')->default(false);
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete(); $t->index(['product_id', 'display_order', 'id']);
        });
        Schema::create('product_size_options', function (Blueprint $t): void {
            $t->id(); $t->string('label', 120); $t->decimal('height', 10, 2); $t->decimal('width', 10, 2);
            $t->decimal('price_override', 19, 2)->nullable(); $t->boolean('active')->default(true); $t->integer('display_order')->default(0);
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete(); $t->index(['product_id', 'display_order', 'id']);
        });
        Schema::create('public_customers', function (Blueprint $t): void {
            $t->id(); $t->string('name', 120)->nullable(); $phone = $t->string('phone_number', 20); $t->string('email', 160)->nullable();
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) $phone->collation('utf8mb4_bin');
            $phone->unique();
            $t->string('remember_token', 120)->nullable(); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('public_customer_addresses', function (Blueprint $t): void {
            $t->id(); $t->string('label', 80)->nullable(); $t->string('recipient_name', 120); $t->string('phone_number', 20);
            $t->string('address_line1', 255); $t->string('address_line2', 255)->nullable(); $t->string('city', 80)->nullable();
            $t->string('state', 80)->nullable(); $t->string('pincode', 20)->nullable(); $t->boolean('default_address')->default(false);
            $t->foreignId('customer_id')->constrained('public_customers')->cascadeOnDelete(); $t->index(['customer_id', 'default_address', 'id']);
        });
        Schema::create('public_orders', function (Blueprint $t): void {
            $t->id(); $orderNumber = $t->string('order_number', 40); $t->string('customer_name_snapshot', 120); $t->string('phone_snapshot', 20);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) $orderNumber->collation('utf8mb4_bin');
            $orderNumber->unique();
            $t->string('address_snapshot', 1000); $t->string('status', 30)->default('PENDING'); $t->decimal('subtotal', 19, 2)->default(0);
            $t->decimal('total_amount', 19, 2)->default(0); $t->string('note', 1000)->nullable(); $t->timestamps();
            $t->foreignId('customer_id')->constrained('public_customers')->restrictOnDelete(); $t->index(['status', 'created_at']);
        });
        Schema::create('public_order_items', function (Blueprint $t): void {
            $t->id(); $t->string('product_name_snapshot', 160); $t->string('product_image_snapshot', 1000)->nullable();
            $t->string('selected_size_label', 120); $t->decimal('selected_height', 10, 2); $t->decimal('selected_width', 10, 2);
            $t->unsignedInteger('quantity'); $t->decimal('unit_price', 19, 2); $t->decimal('total_price', 19, 2);
            $t->foreignId('order_id')->constrained('public_orders')->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete(); $t->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_order_items'); Schema::dropIfExists('public_orders');
        Schema::dropIfExists('public_customer_addresses'); Schema::dropIfExists('public_customers');
        Schema::dropIfExists('product_size_options'); Schema::dropIfExists('product_images');
        Schema::dropIfExists('products'); Schema::dropIfExists('product_categories');
    }
};
