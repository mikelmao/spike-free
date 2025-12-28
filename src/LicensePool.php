<?php

namespace Opcodes\Spike;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Opcodes\Spike\Contracts\SpikeBillable;

/**
 * Represents a pool of licenses for a specific type, owned by a billable entity.
 *
 * @property-read int $id
 * @property string $billable_type
 * @property string $billable_id
 * @property string $license_type
 * @property int $included Licenses included with subscription plan
 * @property int $purchased Additional licenses purchased
 * @property int $bundled Licenses bundled from other license types
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 *
 * @property-read SpikeBillable|Model $billable
 * @property-read \Illuminate\Database\Eloquent\Collection|LicenseAllocation[] $allocations
 */
class LicensePool extends Model
{
    use HasFactory;

    protected $table = 'spike_license_pools';

    protected $fillable = [
        'billable_type',
        'billable_id',
        'license_type',
        'included',
        'purchased',
        'bundled',
    ];

    protected $casts = [
        'included' => 'integer',
        'purchased' => 'integer',
        'bundled' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'included' => 0,
        'purchased' => 0,
        'bundled' => 0,
    ];

    /**
     * Get the billable entity that owns this license pool.
     *
     * @return MorphTo|SpikeBillable|Model
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    /**
     * Get all allocations for this license pool.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LicenseAllocation::class, 'license_pool_id');
    }

    /**
     * Get the LicenseType instance for this pool.
     */
    public function getLicenseTypeAttribute(): LicenseType
    {
        return LicenseType::make($this->attributes['license_type'] ?? 'default');
    }

    /**
     * Get the total number of licenses available in this pool.
     */
    public function total(): int
    {
        return $this->included + $this->purchased + $this->bundled;
    }

    /**
     * Get the number of licenses currently allocated.
     */
    public function allocated(): int
    {
        return $this->allocations()->count();
    }

    /**
     * Get the number of licenses available for allocation.
     */
    public function available(): int
    {
        return max(0, $this->total() - $this->allocated());
    }

    /**
     * Check if there are licenses available for allocation.
     */
    public function hasAvailable(int $quantity = 1): bool
    {
        return $this->available() >= $quantity;
    }

    /**
     * Check if the pool is over-allocated.
     */
    public function isOverAllocated(): bool
    {
        return $this->allocated() > $this->total();
    }

    /**
     * Scope to filter by billable entity.
     */
    public function scopeWhereBillable(Builder $query, SpikeBillable|Model $billable): void
    {
        $query->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey());
    }

    /**
     * Scope to filter by license type.
     */
    public function scopeWhereLicenseType(Builder $query, LicenseType|string $licenseType): void
    {
        $query->where('license_type', LicenseType::make($licenseType)->type);
    }

    /**
     * Get or create a license pool for a billable and license type.
     */
    public static function getOrCreate(SpikeBillable|Model $billable, LicenseType|string $licenseType): self
    {
        return static::firstOrCreate([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'license_type' => LicenseType::make($licenseType)->type,
        ]);
    }
}

