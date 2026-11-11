<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_stages', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->string('name', 150);
            $t->integer('display_order')->default(0); $t->boolean('active')->default(true); $t->timestamps();
            $t->unique(['user_id', 'name']); $t->index(['user_id', 'active', 'display_order', 'id']);
        });
        Schema::create('production_stage_flows', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->string('name', 150);
            $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['user_id', 'name']);
        });
        Schema::create('production_stage_flow_steps', function (Blueprint $t) {
            $t->id(); $t->foreignId('flow_id')->constrained('production_stage_flows')->cascadeOnDelete();
            $t->foreignId('stage_id')->constrained('production_stages'); $t->integer('sequence');
            $t->unique(['flow_id', 'stage_id']); $t->unique(['flow_id', 'sequence']);
        });
        Schema::create('production_components', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->string('name', 150);
            $t->decimal('fixed_rate', 19, 2)->default(0); $t->boolean('active')->default(true); $t->timestamps();
            $t->string('name_ci', 150)->storedAs('lower(`name`)'); $t->unique(['user_id', 'name_ci']);
            $t->index(['user_id', 'active', 'name']);
        });
        Schema::create('production_stage_flow_components', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users');
            $t->foreignId('flow_id')->constrained('production_stage_flows')->cascadeOnDelete();
            $t->foreignId('component_id')->constrained('production_components'); $t->integer('display_order')->default(0);
            $t->unique(['flow_id', 'component_id']); $t->index(['user_id', 'flow_id', 'display_order', 'id'], 'idx_prod_flow_components');
        });
        Schema::create('worker_stage_mappings', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->foreignId('worker_id')->constrained('workers');
            $t->foreignId('stage_id')->constrained('production_stages'); $t->decimal('rate_per_square_foot', 19, 4)->nullable();
            $t->timestamps(); $t->unique(['worker_id', 'stage_id']); $t->index(['user_id', 'worker_id']);
        });
        Schema::create('order_stage_assignments', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->foreignId('order_id')->constrained('orders');
            $t->foreignId('stage_id')->constrained('production_stages'); $t->string('stage_name_snapshot', 150);
            $t->foreignId('worker_id')->constrained('workers'); $t->decimal('square_feet_snapshot', 19, 4)->nullable();
            $t->decimal('rate_snapshot', 19, 4)->nullable(); $t->string('rate_source', 30)->nullable();
            $t->decimal('expected_amount', 19, 2)->nullable(); $t->decimal('earned_amount', 19, 2)->nullable();
            $t->string('status', 30); $t->dateTime('picked_at')->useCurrent(); $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable(); $t->timestamps();
            $t->unsignedBigInteger('open_stage_id')->nullable()->storedAs("case when `status` in ('PICKED','IN_PROGRESS') then `stage_id` else null end");
            $t->unique(['order_id', 'open_stage_id'], 'uk_order_stage_assignments_open');
            $t->index(['user_id', 'worker_id', 'status', 'picked_at'], 'idx_stage_assignment_worker');
        });
        Schema::create('order_component_charges', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users'); $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('component_id')->nullable()->constrained('production_components')->nullOnDelete();
            $t->string('component_name_snapshot', 150); $t->decimal('rate_snapshot', 19, 2);
            $t->unsignedInteger('quantity_snapshot'); $t->decimal('amount', 19, 2); $t->integer('display_order')->default(0);
            $t->index(['order_id', 'display_order', 'id']); $t->index(['user_id', 'order_id']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->foreign('default_production_stage_flow_id')->references('id')->on('production_stage_flows'));
        Schema::table('orders', fn (Blueprint $t) => $t->foreign('production_stage_flow_id')->references('id')->on('production_stage_flows'));
        $names = ['Pending','Pause','Processing','Welding','Bending Cutting Steel','LED Processing','Glass Cutting With Frosted','Polishing','Dispatched for Coloring','Returned from Coloring','Completed','Delivered via Porter','Client Picked Up','Billed'];
        foreach (DB::table('users')->pluck('id') as $userId) foreach ($names as $i => $name) {
            DB::table('production_stages')->insertOrIgnore(['user_id'=>$userId,'name'=>$name,'display_order'=>$i+1,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropForeign(['production_stage_flow_id']));
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['default_production_stage_flow_id']));
        foreach (['order_component_charges','order_stage_assignments','worker_stage_mappings','production_stage_flow_components','production_components','production_stage_flow_steps','production_stage_flows','production_stages'] as $table) Schema::dropIfExists($table);
    }
};
