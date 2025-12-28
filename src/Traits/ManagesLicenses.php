<?php

namespace Opcodes\Spike\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\LicenseAllocation;
use Opcodes\Spike\LicenseManager;
use Opcodes\Spike\LicensePool;
use Opcodes\Spike\LicenseType;

/**
 * Trait for billable models to manage licenses.
 * Add this trait to your Tenant or other billable model.
 *
 * @mixin Model
 */
trait ManagesLicenses
{
    /**
     * Get a LicenseManager instance scoped to this billable.
     */
    public function licenses(): LicenseManager
    {
        return Licenses::billable($this);
    }

    /**
     * Get all license pools for this billable.
     */
    public function licensePools(): MorphMany
    {
        $model = Spike::licensePoolModel();

        return $this->morphMany($model, 'billable');
    }

    /**
     * Get the license pool for a specific type.
     */
    public function licensePool(LicenseType|string $type): LicensePool
    {
        $model = Spike::licensePoolModel();

        return $model::getOrCreate($this, $type);
    }

    /**
     * Check if this billable has available licenses of a type.
     */
    public function hasAvailableLicenses(LicenseType|string $type, int $quantity = 1): bool
    {
        return $this->licenses()->type($type)->hasAvailable($quantity);
    }

    /**
     * Get the number of available licenses of a type.
     */
    public function availableLicenses(LicenseType|string $type): int
    {
        return $this->licenses()->type($type)->available();
    }

    /**
     * Get the total number of licenses of a type.
     */
    public function totalLicenses(LicenseType|string $type): int
    {
        return $this->licenses()->type($type)->total();
    }

    /**
     * Get the number of allocated licenses of a type.
     */
    public function allocatedLicenses(LicenseType|string $type): int
    {
        return $this->licenses()->type($type)->allocated();
    }

    /**
     * Allocate a license to an entity.
     *
     * @throws \Opcodes\Spike\Exceptions\InsufficientLicensesException
     * @throws \Opcodes\Spike\Exceptions\LicenseAlreadyAllocatedException
     */
    public function allocateLicense(
        LicenseType|string $type,
        Model $allocatable,
        ?string $slot = null,
        ?array $metadata = null,
        ?string $reason = null
    ): LicenseAllocation {
        return $this->licenses()
            ->type($type)
            ->allocate($allocatable, $slot, $metadata, $reason);
    }

    /**
     * Release a license from an entity.
     *
     * @throws \Opcodes\Spike\Exceptions\OverAllocationException
     */
    public function releaseLicense(
        LicenseType|string $type,
        Model $allocatable,
        ?string $slot = null
    ): bool {
        return $this->licenses()
            ->type($type)
            ->release($allocatable, $slot);
    }

    /**
     * Check if a license is allocated to an entity.
     */
    public function hasLicenseAllocatedTo(
        LicenseType|string $type,
        Model $allocatable,
        ?string $slot = null
    ): bool {
        return $this->licenses()
            ->type($type)
            ->isAllocatedTo($allocatable, $slot);
    }

    /**
     * Get a summary of all license pools.
     */
    public function licenseSummary(): array
    {
        return $this->licenses()->allPools()->toArray();
    }
}

