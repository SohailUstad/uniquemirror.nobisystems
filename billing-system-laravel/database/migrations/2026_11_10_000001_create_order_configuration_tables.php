<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_option_groups',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name',100);$t->string('input_type',30)->default('SELECT');$t->boolean('required')->default(true);$t->integer('sequence')->default(0);$t->boolean('active')->default(true);$t->timestamps();$t->index(['user_id','active','sequence','name'],'order_opt_groups_owner_active_sort');});
        Schema::create('order_option_values',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('group_id');$t->string('value',100);$t->boolean('allows_custom')->default(false);$t->integer('sequence')->default(0);$t->boolean('active')->default(true);$t->timestamps();$t->index(['user_id','group_id','active','sequence'],'order_opt_values_owner_group_sort');});
        Schema::create('order_flows',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name',100);$t->unsignedBigInteger('root_group_id');$t->boolean('active')->default(true);$t->timestamps();$t->index(['user_id','active','name'],'order_flows_owner_active_sort');});
        Schema::create('order_flow_edges',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('flow_id');$t->unsignedBigInteger('from_group_id');$t->unsignedBigInteger('selected_value_id');$t->unsignedBigInteger('to_group_id');$t->integer('sequence')->default(0);$t->timestamp('created_at')->nullable();$t->unique(['flow_id','from_group_id','selected_value_id'],'uk_order_flow_edge_selection');$t->index(['user_id','flow_id','sequence','id'],'order_flow_edges_owner_flow_sort');});
        Schema::create('orders_counter',function(Blueprint $t){$t->increments('id');$t->string('status',100);$t->unsignedInteger('user_id');$t->integer('count')->default(0);$t->unique(['status','user_id'],'uk_orders_counter_status_user');$t->index('user_id','orders_counter_user_idx');});
    }
    public function down(): void
    {
        Schema::dropIfExists('orders_counter');Schema::dropIfExists('order_flow_edges');Schema::dropIfExists('order_flows');Schema::dropIfExists('order_option_values');Schema::dropIfExists('order_option_groups');
    }
};
