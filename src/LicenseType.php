<?php

namespace Opcodes\Spike;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Represents a type of license that can be allocated to entities.
 *
 * @property-read string $type The license type identifier
 */
class LicenseType implements Arrayable
{
    public function __construct(
        public string $type,
    ) {}

    /**
     * Create a LicenseType instance from a string or existing instance.
     */
    public static function make(LicenseType|string $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return new static($type);
    }

    /**
     * Get the default license type.
     */
    public static function default(): self
    {
        $types = self::all();

        return $types->first() ?? new static('default');
    }

    /**
     * Get all configured license types.
     */
    public static function all(): Collection
    {
        return collect(config('spike.license_types', []))
            ->map(fn (array $config) => LicenseType::make($config['id']));
    }

    /**
     * Find a license type by its ID.
     */
    public static function find(string $id): ?self
    {
        $config = collect(config('spike.license_types', []))
            ->firstWhere('id', $id);

        if (! $config) {
            return null;
        }

        return static::make($config['id']);
    }

    /**
     * Support var_export() for caching.
     */
    public static function __set_state(array $data): LicenseType
    {
        if (isset($data['type'])) {
            return static::make($data['type']);
        }

        return static::default();
    }

    /**
     * Get the configuration for this license type.
     */
    public function config(): array
    {
        return Arr::first(
            config('spike.license_types', []),
            fn ($config) => $config['id'] === $this->type,
            $this->defaultConfig()
        );
    }

    /**
     * Get the human-readable name of this license type.
     */
    public function name(int $count = 1): string
    {
        $config = $this->config();

        if (isset($config['translation_key'])) {
            return trans_choice($config['translation_key'], $count);
        }

        return $config['name'] ?? $this->type;
    }

    /**
     * Check if this license type matches another.
     */
    public function is(LicenseType|string $type): bool
    {
        return $this->type === LicenseType::make($type)->type;
    }

    /**
     * Check if this license type is valid (configured).
     */
    public function isValid(): bool
    {
        return self::all()->contains('type', $this->type);
    }

    /**
     * Get the icon URL for this license type.
     */
    public function icon(): ?string
    {
        return $this->config()['icon'] ?? null;
    }

    /**
     * Get the Stripe price ID for this license type.
     */
    public function priceId(): ?string
    {
        return $this->config()['stripe_price_id'] ?? null;
    }

    /**
     * Get the price in cents for this license type.
     */
    public function priceInCents(): int
    {
        return $this->config()['price_in_cents'] ?? 0;
    }

    /**
     * Get the bundled licenses configuration.
     * Returns an array of [license_type => quantity] that this license bundles.
     */
    public function bundles(): array
    {
        return $this->config()['bundles'] ?? [];
    }

    /**
     * Check if this license type bundles other licenses.
     */
    public function hasBundles(): bool
    {
        return ! empty($this->bundles());
    }

    /**
     * Get the quantity of a specific license type bundled with this one.
     */
    public function bundledQuantity(LicenseType|string $type): int
    {
        $type = LicenseType::make($type);

        return $this->bundles()[$type->type] ?? 0;
    }

    /**
     * Get a formatted string describing the bundled licenses.
     * Example: "3 Agent Seats" or "2 Agent Seats, 1 CM Instance"
     */
    public function bundledLicensesDescription(): string
    {
        $bundles = $this->bundles();

        if (empty($bundles)) {
            return '';
        }

        $parts = [];
        foreach ($bundles as $typeId => $quantity) {
            $bundledType = LicenseType::make($typeId);
            $parts[] = $quantity.' '.$bundledType->name($quantity);
        }

        return implode(', ', $parts);
    }

    /**
     * Get the allocatable model class for this license type.
     */
    public function allocatableClass(): ?string
    {
        return $this->config()['allocatable_class'] ?? null;
    }

    /**
     * Get the default configuration.
     */
    protected function defaultConfig(): array
    {
        return [
            'id' => $this->type,
            'name' => $this->type,
            'icon' => null,
            'stripe_price_id' => null,
            'price_in_cents' => 0,
            'bundles' => [],
        ];
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name(),
            'icon' => $this->icon(),
            'price_in_cents' => $this->priceInCents(),
            'bundles' => $this->bundles(),
        ];
    }
}

