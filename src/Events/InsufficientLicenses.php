<?php

namespace Opcodes\Spike\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\LicenseType;

/**
 * Dispatched when an allocation attempt fails due to insufficient licenses.
 * Useful for triggering notifications or upsell prompts.
 */
class InsufficientLicenses
{
    use Dispatchable;

    /**
     * @param SpikeBillable $billable The billable entity that owns the license pool
     * @param LicenseType $licenseType The type of license that was insufficient
     * @param Model $allocatable The entity that was attempted to be allocated
     * @param int $requested The number of licenses requested
     * @param int $available The number of licenses available
     */
    public function __construct(
        public mixed $billable,
        public LicenseType $licenseType,
        public Model $allocatable,
        public int $requested,
        public int $available,
    ) {}
}

