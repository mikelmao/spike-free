<?php

namespace Opcodes\Spike\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\LicenseAllocation;
use Opcodes\Spike\LicenseManager;
use Opcodes\Spike\LicensePool;
use Opcodes\Spike\LicenseType;

/**
 * @method static SpikeBillable|Model getBillable()
 * @method static LicenseManager billable(SpikeBillable|null $billable = null)
 * @method static LicenseManager type(LicenseType|string $licenseType)
 * @method static LicensePool pool()
 * @method static int total()
 * @method static int allocated()
 * @method static int available()
 * @method static bool hasAvailable(int $quantity = 1)
 * @method static Collection|LicenseAllocation[] allocations()
 * @method static bool isAllocatedTo(Model $allocatable, ?string $slot = null)
 * @method static LicenseAllocation|null getAllocation(Model $allocatable, ?string $slot = null)
 * @method static LicenseAllocation allocate(Model $allocatable, ?string $slot = null, ?array $metadata = null, ?string $reason = null)
 * @method static bool release(Model $allocatable, ?string $slot = null)
 * @method static void setIncluded(int $quantity)
 * @method static void setPurchased(int $quantity)
 * @method static void addPurchased(int $quantity)
 * @method static void removePurchased(int $quantity)
 * @method static void addBundled(int $quantity, LicenseType|string $fromType)
 * @method static void clearCache()
 * @method static Collection licenseTypes()
 * @method static Collection allPools()
 *
 * @see \Opcodes\Spike\LicenseManager
 */
class Licenses extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LicenseManager::class;
    }
}

