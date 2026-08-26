<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('verification_status', 30)
                ->default('pending')
                ->after('profile_completed');

            $table->text('verification_rejection_reason')
                ->nullable()
                ->after('verification_status');

            $table->timestamp('verification_submitted_at')
                ->nullable()
                ->after('verification_rejection_reason');

            $table->timestamp('verification_reviewed_at')
                ->nullable()
                ->after('verification_submitted_at');

            $table->foreignId('verification_reviewed_by')
                ->nullable()
                ->after('verification_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verification_reviewed_by');
            $table->dropColumn([
                'verification_status',
                'verification_rejection_reason',
                'verification_submitted_at',
                'verification_reviewed_at',
            ]);
        });
    }
};
