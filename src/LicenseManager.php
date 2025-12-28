<?php

namespace Opcodes\Spike;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Opcodes\Spike\Events\LicenseAllocated;
use Opcodes\Spike\Events\LicensePoolUpdated;
use Opcodes\Spike\Events\LicenseReleased;
use Opcodes\Spike\Exceptions\InsufficientLicensesException;
use Opcodes\Spike\Exceptions\LicenseAlreadyAllocatedException;
use Opcodes\Spike\Exceptions\OverAllocationException;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\Traits\ScopedToBillable;

/**
 * Service class for managing license pools and allocations.
 * Provides a fluent interface for license operations scoped to a billable and license type.
 */
class LicenseManager
{
    use ScopedToBillable;

    protected ?LicenseType $licenseType = null;

    /**
     * Scope to a specific license type.
     */
    public function type(LicenseType|string $licenseType): static
    {
        $instance = clone $this;
        $instance->licenseType = LicenseType::make($licenseType);

        if (! $instance->licenseType->isValid()) {
            throw new \InvalidArgumentException(
                "Invalid license type: \"{$instance->licenseType->type}\". Please make sure it is configured."
            );
        }

        return $instance;
    }

    /**
     * Get the current license type.
     */
    public function getLicenseType(): LicenseType
    {
        return $this->licenseType ?? LicenseType::default();
    }

    /**
     * Get or create the license pool for the current billable and type.
     */
    public function pool(): LicensePool
    {
        $model = Spike::licensePoolModel();

        return $model::getOrCreate($this->getBillable(), $this->getLicenseType());
    }

    /**
     * Get the total number of licenses in the pool.
     */
    public function total(): int
    {
        return $this->pool()->total();
    }

    /**
     * Get the number of allocated licenses.
     */
    public function allocated(): int
    {
        return $this->pool()->allocated();
    }

    /**
     * Get the number of available licenses.
     */
    public function available(): int
    {
        return $this->pool()->available();
    }

    /**
     * Check if licenses are available for allocation.
     */
    public function hasAvailable(int $quantity = 1): bool
    {
        return $this->pool()->hasAvailable($quantity);
    }

    /**
     * Get all allocations for the current pool.
     */
    public function allocations(): Collection
    {
        return $this->pool()->allocations;
    }

    /**
     * Check if an entity has an allocation in this pool.
     */
    public function isAllocatedTo(Model $allocatable, ?string $slot = null): bool
    {
        return $this->pool()->allocations()
            ->forAllocatable($allocatable)
            ->forSlot($slot)
            ->exists();
    }

    /**
     * Get the allocation for a specific entity.
     */
    public function getAllocation(Model $allocatable, ?string $slot = null): ?LicenseAllocation
    {
        return $this->pool()->allocations()
            ->forAllocatable($allocatable)
            ->forSlot($slot)
            ->first();
    }

    /**
     * Allocate a license to an entity.
     *
     * @throws InsufficientLicensesException
     * @throws LicenseAlreadyAllocatedException
     */
    public function allocate(
        Model $allocatable,
        ?string $slot = null,
        ?array $metadata = null,
        ?string $reason = null
    ): LicenseAllocation {
        return DB::transaction(function () use ($allocatable, $slot, $metadata, $reason) {
            $pool = $this->pool();

            // Check if already allocated
            if ($this->isAllocatedTo($allocatable, $slot)) {
                throw new LicenseAlreadyAllocatedException(
                    "License already allocated to this entity" . ($slot ? " for slot '{$slot}'" : "")
                );
            }

            // Check availability
            if (! $pool->hasAvailable()) {
                throw new InsufficientLicensesException(
                    "No {$this->getLicenseType()->name()} licenses available. " .
                    "Total: {$pool->total()}, Allocated: {$pool->allocated()}"
                );
            }

            // Create allocation using configured model class
            $allocationModel = Spike::licenseAllocationModel();
            $allocation = $allocationModel::create([
                'license_pool_id' => $pool->id,
                'allocatable_type' => $allocatable->getMorphClass(),
                'allocatable_id' => $allocatable->getKey(),
                'slot' => $slot,
                'metadata' => $metadata,
                'reason' => $reason,
            ]);

            $this->clearCache();

            event(new LicenseAllocated($this->getBillable(), $allocation, $this->getLicenseType()));

            return $allocation;
        }, 3);
    }

    /**
     * Release a license allocation from an entity.
     *
     * Note: Releasing an allocation returns the license to the pool - it does NOT
     * reduce the total number of licenses the billable has. Bundled licenses are
     * only affected when the total license count changes (via setPurchased/setIncluded),
     * not when allocations are released.
     */
    public function release(Model $allocatable, ?string $slot = null): bool
    {
        return DB::transaction(function () use ($allocatable, $slot) {
            $allocation = $this->getAllocation($allocatable, $slot);

            if (! $allocation) {
                return false;
            }

            $allocation->delete();

            $this->clearCache();

            event(new LicenseReleased($this->getBillable(), $allocatable, $this->getLicenseType()));

            return true;
        }, 3);
    }

    /**
     * Check if releasing a license allocation would be safe.
     *
     * Releasing an allocation always succeeds as long as the allocation exists,
     * since it simply returns the license to the pool without changing the total
     * number of licenses the billable has.
     */
    public function canRelease(Model $allocatable, ?string $slot = null): bool
    {
        return $this->getAllocation($allocatable, $slot) !== null;
    }

    /**
     * Check if removing a specific quantity of purchased licenses would be safe.
     * Returns true if the removal is safe, false otherwise.
     *
     * @param int $quantity The number of licenses to remove
     */
    public function canRemovePurchased(int $quantity): array|bool
    {
        $pool = $this->pool();
        $licenseType = $this->getLicenseType();
        $billable = $this->getBillable();
        $poolModel = Spike::licensePoolModel();

        // Check 1: Would this cause over-allocation in the current pool?
        $newPurchased = max(0, $pool->purchased - $quantity);
        $newTotal = $pool->included + $newPurchased + $pool->bundled;

        if ($pool->allocated() > $newTotal) {
            $overBy = $pool->allocated() - $newTotal;

            return [
                'can_remove' => false,
                'reason' => 'direct_allocation',
                'license_type' => $licenseType->type,
                'license_name' => $licenseType->name(),
                'allocated' => $pool->allocated(),
                'would_have' => $newTotal,
                'over_by' => $overBy,
                'message' => __('spike::translations.must_deallocate_licenses_first', [
                    'count' => $overBy,
                    'name' => $licenseType->name($overBy),
                ]),
            ];
        }

        // Check 2: Would removing these licenses cause over-allocation in bundled pools?
        foreach ($licenseType->bundles() as $bundledType => $quantityPerLicense) {
            $bundledPool = $poolModel::query()
                ->whereBillable($billable)
                ->whereLicenseType($bundledType)
                ->first();

            if ($bundledPool) {
                // Calculate new bundled amount after removal
                $currentTotal = $pool->included + $pool->purchased;
                $newParentTotal = $pool->included + $newPurchased;
                $bundledReduction = $quantity * $quantityPerLicense;
                $newBundledTotal = max(0, $bundledPool->bundled - $bundledReduction);
                $newChildTotal = $bundledPool->included + $bundledPool->purchased + $newBundledTotal;

                if ($bundledPool->allocated() > $newChildTotal) {
                    $overBy = $bundledPool->allocated() - $newChildTotal;
                    $bundledLicenseType = LicenseType::make($bundledType);

                    return [
                        'can_remove' => false,
                        'reason' => 'bundled_allocation',
                        'license_type' => $bundledType,
                        'license_name' => $bundledLicenseType->name(),
                        'parent_type' => $licenseType->type,
                        'parent_name' => $licenseType->name(),
                        'allocated' => $bundledPool->allocated(),
                        'would_have' => $newChildTotal,
                        'over_by' => $overBy,
                        'bundled_per_parent' => $quantityPerLicense,
                        'message' => __('spike::translations.must_deallocate_bundled_licenses_first', [
                            'count' => $overBy,
                            'name' => $bundledLicenseType->name($overBy),
                            'parent_count' => $quantity,
                            'parent_name' => $licenseType->name($quantity),
                            'bundled_count' => $bundledReduction,
                        ]),
                    ];
                }
            }
        }

        return true;
    }

    /**
     * Set the included license quantity (from subscription plan).
     */
    public function setIncluded(int $quantity): void
    {
        $pool = $this->pool();
        $oldQuantity = $pool->included;
        $pool->included = $quantity;
        $pool->save();

        $this->clearCache();

        if ($oldQuantity !== $quantity) {
            event(new LicensePoolUpdated($this->getBillable(), $pool, $this->getLicenseType(), 'included'));
        }
    }

    /**
     * Set the purchased license quantity.
     * Also updates bundled licenses in child pools based on the new total.
     */
    public function setPurchased(int $quantity): void
    {
        $pool = $this->pool();
        $oldQuantity = $pool->purchased;
        $pool->purchased = $quantity;
        $pool->save();

        $this->clearCache();

        if ($oldQuantity !== $quantity) {
            // Sync bundled licenses in child pools
            $this->syncBundledLicenses();

            event(new LicensePoolUpdated($this->getBillable(), $pool, $this->getLicenseType(), 'purchased'));
        }
    }

    /**
     * Sync bundled licenses in child pools based on the total licenses of this type.
     * This ensures bundled quantities are always correct based on total (included + purchased).
     */
    protected function syncBundledLicenses(): void
    {
        $licenseType = $this->getLicenseType();
        $billable = $this->getBillable();
        $pool = $this->pool();
        $poolModel = Spike::licensePoolModel();

        // Total licenses of this type (included from plan + purchased)
        $totalLicenses = $pool->included + $pool->purchased;

        foreach ($licenseType->bundles() as $bundledType => $quantityPerLicense) {
            // Calculate what the bundled amount should be
            $expectedBundled = $totalLicenses * $quantityPerLicense;

            $bundledPool = $poolModel::query()
                ->whereBillable($billable)
                ->whereLicenseType($bundledType)
                ->first();

            if ($bundledPool) {
                if ($bundledPool->bundled !== $expectedBundled) {
                    $bundledPool->bundled = $expectedBundled;
                    $bundledPool->save();

                    event(new LicensePoolUpdated($billable, $bundledPool, LicenseType::make($bundledType), 'bundled'));
                }
            } else {
                // Create the bundled pool if it doesn't exist
                $bundledPool = $poolModel::create([
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->getKey(),
                    'license_type' => $bundledType,
                    'included' => 0,
                    'purchased' => 0,
                    'bundled' => $expectedBundled,
                ]);

                event(new LicensePoolUpdated($billable, $bundledPool, LicenseType::make($bundledType), 'bundled'));
            }
        }
    }

    /**
     * Add to the purchased license quantity.
     * Also updates bundled licenses in child pools based on the new total.
     */
    public function addPurchased(int $quantity): void
    {
        $pool = $this->pool();
        $pool->increment('purchased', $quantity);

        $this->clearCache();

        // Sync bundled licenses in child pools
        $this->syncBundledLicenses();

        event(new LicensePoolUpdated($this->getBillable(), $pool, $this->getLicenseType(), 'purchased'));
    }

    /**
     * Remove from the purchased license quantity.
     * Also updates bundled licenses in child pools based on the new total.
     *
     * @throws OverAllocationException
     */
    public function removePurchased(int $quantity): void
    {
        $pool = $this->pool();
        $newTotal = $pool->included + max(0, $pool->purchased - $quantity) + $pool->bundled;

        if ($pool->allocated() > $newTotal) {
            throw new OverAllocationException(
                "Cannot remove {$quantity} purchased licenses: would cause over-allocation. " .
                "Currently allocated: {$pool->allocated()}, would have: {$newTotal}"
            );
        }

        $pool->decrement('purchased', min($quantity, $pool->purchased));

        $this->clearCache();

        // Sync bundled licenses in child pools
        $this->syncBundledLicenses();

        event(new LicensePoolUpdated($this->getBillable(), $pool, $this->getLicenseType(), 'purchased'));
    }

    /**
     * Add bundled licenses (called when parent license type is provisioned).
     */
    public function addBundled(int $quantity, LicenseType|string $fromType): void
    {
        $pool = $this->pool();
        $pool->increment('bundled', $quantity);

        $this->clearCache();

        event(new LicensePoolUpdated($this->getBillable(), $pool, $this->getLicenseType(), 'bundled'));
    }

    /**
     * Get the cache key for this billable and license type.
     */
    protected function getCacheKey(): string
    {
        $billable = $this->getBillable();

        return "spike::licenses:{$billable->getMorphClass()}:{$billable->getKey()}:{$this->getLicenseType()->type}";
    }

    /**
     * Clear the cache for this license pool.
     */
    public function clearCache(): void
    {
        Cache::forget($this->getCacheKey());
    }

    /**
     * Get all license types configured.
     */
    public function licenseTypes(): Collection
    {
        return LicenseType::all();
    }

    /**
     * Get summary of all license pools for the billable.
     */
    public function allPools(): Collection
    {
        return $this->licenseTypes()->map(function (LicenseType $type) {
            $pool = $this->type($type)->pool();

            return [
                'type' => $type,
                'total' => $pool->total(),
                'allocated' => $pool->allocated(),
                'available' => $pool->available(),
                'included' => $pool->included,
                'purchased' => $pool->purchased,
                'bundled' => $pool->bundled,
            ];
        });
    }

    /**
     * Check if the billable can switch to a new subscription plan.
     * Returns validation result with any blocking issues.
     *
     * @param array $newPlanLicenses Array of [license_type => quantity] for the new plan
     * @return array{can_switch: bool, issues: array}
     */
    public function canSwitchToPlan(array $newPlanLicenses): array
    {
        $issues = [];
        $billable = $this->getBillable();

        foreach ($this->licenseTypes() as $licenseType) {
            $pool = $this->type($licenseType)->pool();
            $currentAllocated = $pool->allocated();

            // Calculate new total after plan switch
            $newIncluded = $newPlanLicenses[$licenseType->type] ?? 0;
            $newTotal = $newIncluded + $pool->purchased;

            // Calculate bundled licenses from other types
            foreach ($this->licenseTypes() as $parentType) {
                if ($parentType->bundledQuantity($licenseType) > 0) {
                    $parentNewIncluded = $newPlanLicenses[$parentType->type] ?? 0;
                    $parentPool = $this->type($parentType)->pool();
                    $parentTotal = $parentNewIncluded + $parentPool->purchased;
                    $newTotal += $parentTotal * $parentType->bundledQuantity($licenseType);
                }
            }

            if ($currentAllocated > $newTotal) {
                $overBy = $currentAllocated - $newTotal;
                $issues[] = [
                    'license_type' => $licenseType->type,
                    'license_name' => $licenseType->name($overBy),
                    'current_allocated' => $currentAllocated,
                    'new_total' => $newTotal,
                    'over_by' => $overBy,
                    'message' => "Cannot switch plan: {$currentAllocated} {$licenseType->name($currentAllocated)} " .
                        "are allocated, but new plan would only provide {$newTotal}. " .
                        "Please release {$overBy} {$licenseType->name($overBy)} first.",
                ];
            }
        }

        return [
            'can_switch' => empty($issues),
            'issues' => $issues,
        ];
    }

    /**
     * Extract license entitlements from a subscription plan's provides_monthly array.
     *
     * @param \Opcodes\Spike\SubscriptionPlan $plan
     * @return array Array of [license_type => quantity]
     */
    public static function extractLicensesFromPlan($plan): array
    {
        $licenses = [];

        foreach ($plan->provides_monthly as $providable) {
            if ($providable instanceof LicenseEntitlement) {
                $type = $providable->getType()->type;
                $licenses[$type] = ($licenses[$type] ?? 0) + $providable->getAmount();
            }
        }

        return $licenses;
    }
}

