<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the spike_license_pools table for tracking license inventory per billable.
 *
 * @see https://spike.opcodes.io/docs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spike_license_pools', function (Blueprint $table) {
            $table->id();

            // Polymorphic relationship to the billable (e.g., Tenant)
            $table->uuidMorphs('billable');

            // License type identifier (e.g., 'cm_instance', 'agent_seat')
            $table->string('license_type');

            // License quantities
            $table->unsignedInteger('included')->default(0)->comment('Licenses included with subscription plan');
            $table->unsignedInteger('purchased')->default(0)->comment('Additional licenses purchased');
            $table->unsignedInteger('bundled')->default(0)->comment('Licenses bundled from other license types');

            $table->timestamps();

            // Unique constraint: one pool per billable per license type
            $table->unique(['billable_type', 'billable_id', 'license_type'], 'spike_license_pools_unique');

            // Index for efficient lookups
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_license_pools');
    }
};

