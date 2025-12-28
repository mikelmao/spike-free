<?php

namespace Opcodes\Spike\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\LicenseAllocation;
use Opcodes\Spike\LicenseType;

/**
 * Dispatched when a license is allocated to an entity.
 */
class LicenseAllocated
{
    use Dispatchable;

    /**
     * @param SpikeBillable $billable The billable entity that owns the license pool
     * @param LicenseAllocation $allocation The allocation record
     * @param LicenseType $licenseType The type of license allocated
     */
    public function __construct(
        public mixed $billable,
        public LicenseAllocation $allocation,
        public LicenseType $licenseType,
    ) {}
}

