<?php

namespace Opcodes\Spike\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\LicensePool;
use Opcodes\Spike\LicenseType;

/**
 * Dispatched when a license pool's quantities are updated.
 */
class LicensePoolUpdated
{
    use Dispatchable;

    /**
     * @param SpikeBillable $billable The billable entity that owns the license pool
     * @param LicensePool $pool The updated license pool
     * @param LicenseType $licenseType The type of license
     * @param string $quantityType Which quantity was updated: 'included', 'purchased', or 'bundled'
     */
    public function __construct(
        public mixed $billable,
        public LicensePool $pool,
        public LicenseType $licenseType,
        public string $quantityType,
    ) {}
}

