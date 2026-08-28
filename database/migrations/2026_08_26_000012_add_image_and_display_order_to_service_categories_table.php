<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'icon' already exists and is used for a simple icon name
     * (e.g. "plumbing"). 'image' is separate — an actual
     * uploaded picture, for a richer category card in the app.
     * Both are optional; the app can fall back to icon if no
     * image has been uploaded.
     */
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('image')->nullable()->after('icon');
            $table->unsignedInteger('display_order')->default(0)->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn(['image', 'display_order']);
        });
    }
};
