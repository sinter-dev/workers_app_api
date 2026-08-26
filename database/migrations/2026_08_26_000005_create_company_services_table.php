<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_profile_id')
                ->constrained('company_profiles')
                ->cascadeOnDelete();

            $table->foreignId('service_category_id')
                ->constrained('service_categories')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'company_profile_id',
                'service_category_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_services');
    }
};
