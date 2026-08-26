<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storage for category-specific extra profile details — e.g.
     * a chef's cuisines, a driver's license class, a plumber's
     * certifications.
     *
     * Every worker keeps the same core profile fields already
     * defined on this table (name, age, district, bio, rate,
     * availability, gallery, etc). This column holds a small,
     * optional set of extra answers relevant only to their
     * specific category — stored as JSON rather than as
     * separate tables per category, since the exact set of
     * extra fields will keep growing as new categories are
     * added, and a new table per category is not sustainable.
     *
     * The exact shape of this JSON is validated in application
     * code, per category, once each category's specific extra
     * questions are defined. This migration only prepares the
     * storage — no application behavior changes yet.
     */
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->json('category_details')->nullable()->after('languages');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn('category_details');
        });
    }
};
