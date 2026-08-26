<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classify each service category as either:
     *
     * - employment: the existing Jobs flow (post a job, workers
     *   apply, hire, employment) — e.g. housemaid, nanny, driver.
     *
     * - on_demand_service: the new Book-a-Service flow (describe
     *   the problem, receive quotes, book a provider) — e.g.
     *   plumber, electrician, cleaning company, pest control.
     *
     * Defaulting every category to 'employment' means every
     * category that exists today keeps working exactly as it
     * does now, with zero behavior change. New on-demand
     * categories are added deliberately afterward.
     *
     * Also adds parent_id so categories can be grouped under a
     * parent (e.g. "Plumber" and "Electrician" both belong to
     * a "Skilled & Home Services" parent category). A category
     * with parent_id = null is a top-level group; a category
     * with parent_id set is a selectable leaf category.
     */
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->enum('transaction_type', [
                'employment',
                'on_demand_service',
            ])->default('employment')->after('slug');

            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('service_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('transaction_type');
        });
    }
};
