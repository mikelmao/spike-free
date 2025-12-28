<?php

namespace Opcodes\Spike;

use Illuminate\Support\Facades\DB;
use Opcodes\Spike\Contracts\CountableProvidable;
use Opcodes\Spike\Contracts\Providable;
use Opcodes\Spike\Facades\Licenses;

/**
 * Represents a license entitlement that can be provided through subscriptions or products.
 * Implements Providable to integrate with Spike's subscription/product provisioning system.
 */
class LicenseEntitlement implements CountableProvidable
{
    protected int $quantity;
    protected ?LicenseType $type;

    public function __construct(
        int $quantity,
        LicenseType|string $type,
    ) {
        $this->setType($type);
        $this->setAmount($quantity);
    }

    /**
     * Support var_export() for caching.
     */
    public static function __set_state(array $data): static
    {
        return new static(
            $data['quantity'] ?? $data['amount'] ?? 0,
            $data['type'] ?? LicenseType::default(),
        );
    }

    /**
     * Create a new LicenseEntitlement instance.
     */
    public static function make(int $quantity, ?string $type = null): self
    {
        return new static($quantity, $type ?? LicenseType::default());
    }

    /**
     * Get the unique key for this providable.
     */
    public function key(): string
    {
        return 'license-entitlement:' . $this->getType()->type;
    }

    /**
     * Get the name of this providable.
     */
    public function name(): string
    {
        return $this->getType()->name(2);
    }

    /**
     * Get the icon URL for this providable.
     */
    public function icon(): ?string
    {
        return $this->getType()->icon();
    }

    /**
     * Check if this is the same providable as another.
     */
    public function isSameProvidable(Providable $providable): bool
    {
        return $providable instanceof LicenseEntitlement
            && $this->getType()->is($providable->getType());
    }

    /**
     * Provide licenses from a subscription plan (monthly provisioning).
     * This sets the "included" quantity in the license pool.
     */
    public function provideMonthlyFromSubscriptionPlan(SubscriptionPlan $subscriptionPlan, $billable): void
    {
        DB::transaction(function () use ($billable) {
            $licenseManager = Licenses::billable($billable)->type($this->getType());

            // Set the included quantity from the subscription
            $licenseManager->setIncluded($this->getAmount());

            // Handle bundled licenses
            $this->provisionBundledLicenses($billable);

            $licenseManager->clearCache();
        }, 3);
    }

    /**
     * Provide licenses from a one-time product purchase.
     * This adds to the "purchased" quantity in the license pool.
     */
    public function provideOnceFromProduct(Product $product, $billable): void
    {
        DB::transaction(function () use ($billable) {
            $licenseManager = Licenses::billable($billable)->type($this->getType());

            // Add to purchased quantity
            $licenseManager->addPurchased($this->getAmount());

            // Handle bundled licenses
            $this->provisionBundledLicenses($billable);

            $licenseManager->clearCache();
        }, 3);
    }

    /**
     * Provision any bundled licenses that come with this license type.
     */
    protected function provisionBundledLicenses($billable): void
    {
        $bundles = $this->getType()->bundles();

        foreach ($bundles as $bundledType => $quantityPerLicense) {
            $totalBundled = $this->getAmount() * $quantityPerLicense;

            Licenses::billable($billable)
                ->type($bundledType)
                ->addBundled($totalBundled, $this->getType());
        }
    }

    /**
     * Set the quantity of licenses.
     */
    public function setAmount(?int $amount = null): self
    {
        $this->quantity = $amount ?? 0;

        return $this;
    }

    /**
     * Get the quantity of licenses.
     */
    public function getAmount(): int
    {
        return $this->quantity ?? 0;
    }

    /**
     * Set the license type.
     */
    public function setType(LicenseType|string|null $type = null): self
    {
        if (is_null($type)) {
            $this->type = null;
        } else {
            $this->type = LicenseType::make($type);
        }

        return $this;
    }

    /**
     * Get the license type.
     */
    public function getType(): LicenseType
    {
        return $this->type ?? LicenseType::default();
    }

    /**
     * Get string representation.
     */
    public function toString(): string
    {
        $string = number_format($this->getAmount());

        if (isset($this->type)) {
            $string .= ' ' . $this->type->name($this->quantity);
        }

        return $string;
    }
}

