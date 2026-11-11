<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) return;

        // PostgreSQL's source UNIQUE(user_id, name) distinguishes letter case.
        DB::statement('ALTER TABLE production_stages MODIFY name VARCHAR(150) COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE production_stage_flows MODIFY name VARCHAR(150) COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) return;

        DB::statement('ALTER TABLE production_stages MODIFY name VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE production_stage_flows MODIFY name VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
