<?php

namespace Opcodes\Spike;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents an allocation of a license to a specific entity.
 *
 * @property-read int $id
 * @property int $license_pool_id
 * @property string $allocatable_type
 * @property string $allocatable_id
 * @property string|null $slot
 * @property array|null $metadata
 * @property string|null $reason
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 *
 * @property-read LicensePool $pool
 * @property-read Model $allocatable
 */
class LicenseAllocation extends Model
{
    use HasFactory;

    protected $table = 'spike_license_allocations';

    protected $fillable = [
        'license_pool_id',
        'allocatable_type',
        'allocatable_id',
        'slot',
        'metadata',
        'reason',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the license pool this allocation belongs to.
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(LicensePool::class, 'license_pool_id');
    }

    /**
     * Get the entity this license is allocated to.
     */
    public function allocatable(): MorphTo
    {
        return $this->morphTo('allocatable');
    }

    /**
     * Get the license type through the pool.
     */
    public function getLicenseTypeAttribute(): LicenseType
    {
        return $this->pool->license_type;
    }

    /**
     * Scope to filter by license pool.
     */
    public function scopeForPool(Builder $query, LicensePool $pool): void
    {
        $query->where('license_pool_id', $pool->id);
    }

    /**
     * Scope to filter by allocatable entity.
     */
    public function scopeForAllocatable(Builder $query, Model $allocatable): void
    {
        $query->where('allocatable_type', $allocatable->getMorphClass())
            ->where('allocatable_id', $allocatable->getKey());
    }

    /**
     * Scope to filter by slot.
     */
    public function scopeForSlot(Builder $query, ?string $slot): void
    {
        if (is_null($slot)) {
            $query->whereNull('slot');
        } else {
            $query->where('slot', $slot);
        }
    }

    /**
     * Check if this allocation is for a specific entity.
     */
    public function isFor(Model $allocatable): bool
    {
        return $this->allocatable_type === $allocatable->getMorphClass()
            && $this->allocatable_id == $allocatable->getKey();
    }

    /**
     * Update the metadata for this allocation.
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);
        $this->save();

        return $this;
    }

    /**
     * Get a specific metadata value.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }
}

