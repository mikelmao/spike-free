<?php

namespace Opcodes\Spike\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\LicenseType;

/**
 * Dispatched when a license is released from an entity.
 */
class LicenseReleased
{
    use Dispatchable;

    /**
     * @param SpikeBillable $billable The billable entity that owns the license pool
     * @param Model $allocatable The entity the license was released from
     * @param LicenseType $licenseType The type of license released
     */
    public function __construct(
        public mixed $billable,
        public Model $allocatable,
        public LicenseType $licenseType,
    ) {}
}

