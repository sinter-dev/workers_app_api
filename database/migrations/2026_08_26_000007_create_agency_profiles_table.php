<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('agency_name')->nullable();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('business_registration_number')->nullable();

            // Descriptive only, e.g. "Domestic Worker Agency",
            // "Recruitment Agency" — not tied to service_categories,
            // since an agency doesn't offer a bookable service
            // itself, it places other people's workers.
            $table->string('specialty')->nullable();

            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('profile_completed')->default(false);

            $table->string('verification_status', 30)->default('pending');
            $table->text('verification_rejection_reason')->nullable();
            $table->timestamp('verification_submitted_at')->nullable();
            $table->timestamp('verification_reviewed_at')->nullable();
            $table->foreignId('verification_reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_profiles');
    }
};
