<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the role enum to support the new provider types
     * introduced by the marketplace expansion (service companies
     * and employment agencies), alongside the existing worker,
     * homeowner, and admin roles.
     *
     * This is additive only: existing rows and existing role
     * values are completely untouched. Laravel's schema builder
     * (via Doctrine DBAL) does not reliably support altering
     * MySQL ENUM column definitions, so this uses a raw
     * ALTER TABLE statement instead, which is the standard safe
     * approach for this specific operation.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE users
                MODIFY COLUMN role ENUM(
                    'homeowner',
                    'worker',
                    'company',
                    'agency',
                    'admin'
                ) NOT NULL DEFAULT 'homeowner'"
        );
    }

    /**
     * Reverting requires no rows to currently hold the new
     * values. If any users have already been created with
     * role = company or role = agency, this down() migration
     * will fail loudly rather than corrupt data, which is the
     * correct behavior.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE users
                MODIFY COLUMN role ENUM(
                    'homeowner',
                    'worker',
                    'admin'
                ) NOT NULL DEFAULT 'homeowner'"
        );
    }
};
