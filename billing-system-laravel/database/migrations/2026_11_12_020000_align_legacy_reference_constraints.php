<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // These two relationships were not constrained in the PostgreSQL source.
        // Existing dump rows include references to clients and orders that were deleted.
        Schema::table('client_bill_sequence', function ($table): void {
            $table->dropForeign(['client_id']);
        });
        Schema::table('dashboard_order_refs', function ($table): void {
            $table->dropForeign(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_bill_sequence', function ($table): void {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
        Schema::table('dashboard_order_refs', function ($table): void {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
