<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'expense_categories'=>['name'=>100], 'expense_vendors'=>['name'=>150,'phone'=>40,'email'=>150],
            'expense_payment_modes'=>['name'=>100], 'expense_payment_statuses'=>['name'=>100],
        ] as $table=>$extra) Schema::create($table, function(Blueprint $t) use($extra,$table): void {
            $t->id(); $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            foreach($extra as $column=>$length) $t->string($column,$length)->nullable($table==='expense_vendors' && $column!=='name');
            $t->string('active_name_key',150)->nullable();
            if($table==='expense_vendors') { $t->string('description',500)->nullable(); }
            elseif($table==='expense_categories'||$table==='expense_payment_modes'||$table==='expense_payment_statuses') $t->string('description',500)->nullable();
            $t->boolean('active')->default(true); $t->timestamps(); $t->index(['user_id','active','name']);
            $t->unique(['user_id','active_name_key']);
        });
        Schema::create('expenses', function(Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('expense_vendors')->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained('expense_payment_modes')->nullOnDelete();
            $t->foreignId('payment_status_id')->nullable()->constrained('expense_payment_statuses')->nullOnDelete();
            $t->string('category_name',100); $t->string('vendor_name',150); $t->string('payment_mode_name',100); $t->string('payment_status_name',100);
            $t->decimal('amount',19,2); $t->date('expense_date'); $t->string('reference_no')->nullable(); $t->string('note',1000)->nullable(); $t->string('receipt_url',1000)->nullable();
            $t->boolean('archived')->default(false); $t->timestamps();
            $t->index(['user_id','expense_date']); $t->index(['user_id','category_id']); $t->index(['user_id','vendor_id']); $t->index(['user_id','payment_mode_id']); $t->index(['user_id','payment_status_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('expenses'); foreach(['expense_payment_statuses','expense_payment_modes','expense_vendors','expense_categories'] as $t) Schema::dropIfExists($t); }
};
