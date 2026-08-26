<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table handles both directions of connecting a worker
     * to an agency:
     *
     * - initiated_by = 'worker': the worker asked to join the
     *   agency; the agency accepts or declines.
     *
     * - initiated_by = 'agency': the agency invited an existing
     *   worker (found by phone number); the worker accepts or
     *   declines.
     *
     * This mirrors how hiring_requests already handles a
     * two-party propose/accept/decline flow in this codebase.
     */
    public function up(): void
    {
        Schema::create('agency_worker_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('worker_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('initiated_by', ['worker', 'agency']);

            $table->enum('status', [
                'pending',
                'accepted',
                'declined',
                'withdrawn',
            ])->default('pending');

            $table->text('message')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_worker_requests');
    }
};
