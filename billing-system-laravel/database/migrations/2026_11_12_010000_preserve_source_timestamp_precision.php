<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** The 58 source tables in the reviewed PostgreSQL dump. */
    private const SOURCE_TABLES = [
        'ai_pending_actions', 'bills', 'categories', 'client_balance_ledger', 'client_balances',
        'client_bill_sequence', 'clients', 'dashboard_daily_targets', 'dashboard_order_refs',
        'expense_categories', 'expense_payment_modes', 'expense_payment_statuses', 'expense_vendors',
        'expenses', 'fcm_device_tokens', 'notes', 'order_component_charges', 'order_flow_edges',
        'order_flows', 'order_images', 'order_option_groups', 'order_option_values',
        'order_stage_assignments', 'order_tracking', 'order_voice_notes', 'orders', 'orders_counter',
        'payments', 'product_categories', 'product_images', 'product_size_options',
        'production_components', 'production_stage_flow_components', 'production_stage_flow_steps',
        'production_stage_flows', 'production_stages', 'products', 'public_customer_addresses',
        'public_customer_otps', 'public_customers', 'public_order_items', 'public_orders',
        'registered_client_fcm_device_tokens', 'registered_client_otps', 'registered_clients',
        'stages', 'user_working_day_settings', 'users', 'worker_accounts',
        'worker_daily_target_overrides', 'worker_daily_targets', 'worker_ledger',
        'worker_order_assignments', 'worker_payout_requests', 'worker_stage_mappings',
        'worker_target_resets', 'worker_working_day_settings', 'workers',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $columns = DB::select(
            <<<'SQL'
SELECT TABLE_NAME AS table_name,
       COLUMN_NAME AS column_name,
       DATA_TYPE AS data_type,
       DATETIME_PRECISION AS datetime_precision,
       IS_NULLABLE AS is_nullable,
       COLUMN_DEFAULT AS column_default,
       EXTRA AS extra
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ?
  AND DATA_TYPE IN ('datetime', 'timestamp')
ORDER BY TABLE_NAME, ORDINAL_POSITION
SQL,
            [$database]
        );

        $allowed = array_fill_keys(self::SOURCE_TABLES, true);
        $tables = [];

        foreach ($columns as $column) {
            $table = (string) $column->table_name;
            if (! isset($allowed[$table])) {
                continue;
            }

            // Generated/auto-updating columns and defaults other than CURRENT_TIMESTAMP
            // cannot be reconstructed safely from INFORMATION_SCHEMA alone.
            $extra = strtoupper(trim((string) $column->extra));
            if ($extra !== '' && $extra !== 'DEFAULT_GENERATED') {
                throw new RuntimeException("Unsupported EXTRA on {$table}.{$column->column_name}: {$extra}");
            }

            $default = $column->column_default;
            $nullable = strtoupper((string) $column->is_nullable) === 'YES';
            // MariaDB returns the text NULL for a nullable column's implicit or
            // explicit DEFAULT NULL; a quoted string literal would be returned
            // as 'NULL' and must not be treated as a null default.
            $nullDefault = $default === null || ($nullable && strtoupper((string) $default) === 'NULL');
            // MariaDB reports a CURRENT_TIMESTAMP default as current_timestamp().
            // MySQL can report CURRENT_TIMESTAMP or CURRENT_TIMESTAMP(n).
            if (! $nullDefault && ! preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]?\))?$/i', (string) $default)) {
                throw new RuntimeException("Unsupported default on {$table}.{$column->column_name}");
            }
            if ($extra === 'DEFAULT_GENERATED' && $nullDefault) {
                throw new RuntimeException("Generated default is missing on {$table}.{$column->column_name}");
            }

            if ((int) $column->datetime_precision >= 6) {
                continue;
            }

            $quote = static fn (string $identifier): string => '`'.str_replace('`', '``', $identifier).'`';
            $definition = $quote((string) $column->column_name).' DATETIME(6) '.
                ($nullable ? 'NULL' : 'NOT NULL');

            if (! $nullDefault) {
                $definition .= ' DEFAULT CURRENT_TIMESTAMP(6)';
            }

            $tables[$table][] = $definition;
        }

        foreach ($tables as $table => $definitions) {
            $quotedTable = '`'.str_replace('`', '``', $table).'`';
            DB::statement('ALTER TABLE '.$quotedTable.' '.implode(', ', array_map(
                static fn (string $definition): string => 'MODIFY COLUMN '.$definition,
                $definitions
            )));
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: reducing precision would discard stored data.
    }
};
