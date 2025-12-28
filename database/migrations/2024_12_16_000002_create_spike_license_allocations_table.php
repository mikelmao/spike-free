<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the spike_license_allocations table for tracking license assignments.
 *
 * @see https://spike.opcodes.io/docs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spike_license_allocations', function (Blueprint $table) {
            $table->id();

            // Reference to the license pool
            $table->foreignId('license_pool_id')
                ->constrained('spike_license_pools')
                ->cascadeOnDelete();

            // Polymorphic relationship to the allocated entity (e.g., ClientCompany, User)
            $table->uuidMorphs('allocatable');

            // Optional: allocation slot identifier for multiple allocations per entity
            $table->string('slot')->nullable()->comment('Slot identifier for multiple allocations');

            // Optional: metadata for the allocation
            $table->json('metadata')->nullable();

            // Reason for allocation (for audit trail)
            $table->string('reason')->nullable();

            $table->timestamps();

            // Unique constraint: one allocation per entity per pool per slot
            $table->unique(
                ['license_pool_id', 'allocatable_type', 'allocatable_id', 'slot'],
                'spike_license_allocations_unique'
            );

            // Index for efficient lookups by allocatable
            $table->index(['allocatable_type', 'allocatable_id'], 'spike_license_allocations_allocatable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_license_allocations');
    }
};

