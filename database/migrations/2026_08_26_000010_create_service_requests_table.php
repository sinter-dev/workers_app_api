<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A service_request is a one-off booking (e.g. "my sink is
     * leaking"), distinct from a job (long-term employment). A
     * homeowner posts one, workers or companies submit quotes
     * against it, and the homeowner accepts one quote to book
     * a provider.
     */
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('homeowner_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('service_category_id')
                ->constrained('service_categories')
                ->restrictOnDelete();

            // Set once a quote is accepted. Can be a worker or a
            // company — both provider types can offer on-demand
            // services.
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description');

            $table->string('address');
            $table->string('district');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->date('preferred_date')->nullable();
            $table->string('preferred_time')->nullable();

            $table->enum('status', [
                'open',
                'booked',
                'in_progress',
                'awaiting_confirmation',
                'completed',
                'cancelled',
            ])->default('open');

            $table->timestamp('booked_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completion_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancelled_by', ['homeowner', 'provider'])->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('cancellation_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'district']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
