# License Pool Billing System

## Executive Summary

This document outlines the feasibility and implementation plan for extending the Spike billing package to support a **tenant-level license pool billing model** for FlowBridge Connect's Conversational Manager (Chatwoot) integration.

### Key Objectives

1. Enable tenants to purchase **Conversational Manager (CM) Instance** licenses ($30/month each)
2. Each CM Instance includes **3 bundled Agent Seat licenses** at no additional cost
3. Allow purchase of **additional Agent Seat licenses** ($10/month each) beyond bundled seats
4. Implement a **pooled license model** where licenses are shared at the tenant level across all ClientCompanies

### Verdict: Feasible with Moderate Restructuring

The existing Spike architecture provides solid extension points through the `Providable` interface and subscription item infrastructure. The license system will operate as a **parallel system** alongside credits, following established patterns from `CreditAmount`, `CreditManager`, and `CreditTransaction`.

---

## Business Model

### Pricing Structure

| License Type | Price | Bundled Benefits | Allocation Target |
|--------------|-------|------------------|-------------------|
| CM Instance | $30/month | 3 Agent Seats included | ClientCompany |
| Agent Seat (Additional) | $10/month | None | User (Chatwoot access) |

### Bundling Logic

```
Total Available Agent Seats = (CM Instances × 3) + Purchased Additional Seats

Example:
- Tenant purchases 2 CM Instances = 6 bundled agent seats
- Tenant purchases 4 additional agent seats
- Total agent seats available = 6 + 4 = 10 seats
```

### Billing Scenarios

#### Scenario 1: Initial Setup
```
Tenant on Pro Plan (includes 1 CM Instance, 3 bundled seats)
- Enables CM for ClientCompany A: Uses 1 CM Instance
- Adds 3 agents: Uses 3/3 bundled seats
- Cost: $0 additional (included in plan)
```

#### Scenario 2: Expansion
```
Tenant needs 2nd ClientCompany with CM
- Purchases additional CM Instance: +$30/month
- Now has: 2 CM Instances, 6 bundled seats
- Adds 2 more agents: Uses 5/6 seats
- Cost: +$30/month
```

#### Scenario 3: Agent Overage
```
Tenant has 2 CM Instances (6 bundled seats)
- Needs 8 agents total
- Purchases 2 additional seats: +$20/month
- Total: 8/8 seats used
- Cost: $30 (2nd CM) + $20 (2 extra seats) = +$50/month
```

### Stripe Product Configuration

```
Products to create in Stripe:
├── CM Instance License (recurring, per-unit)
│   └── price_cm_instance_monthly: $30.00/month
│
└── Agent Seat License (recurring, per-unit)
    └── price_agent_seat_monthly: $10.00/month
```

---

## Technical Feasibility

### Spike Architecture Fit Analysis

Based on research of the Spike codebase, here's how the license system maps to existing patterns:

| Spike Pattern | License System Equivalent | Fit Assessment |
|---------------|--------------------------|----------------|
| `CreditType` | `LicenseType` | ✅ Direct parallel |
| `CreditAmount` (Providable) | `LicenseEntitlement` | ✅ Can implement `Providable` |
| `CreditTransaction` | `LicensePool` + `LicenseAllocation` | ⚠️ Different model (allocatable vs consumable) |
| `CreditManager` | `LicenseManager` | ✅ Similar facade pattern |
| `ManagesCredits` trait | `ManagesLicenses` trait | ✅ Direct parallel |
| `SubscriptionItem.quantity` | License quantities | ✅ Already supports quantity |

### Key Architectural Differences

| Aspect | Credits (Current) | Licenses (Proposed) |
|--------|-------------------|---------------------|
| Nature | Consumable (spent and gone) | Allocatable (can be released) |
| Tracking | Transaction log (sum = balance) | Pool inventory + allocation records |
| Stripe Sync | Not synced | Synced as subscription item quantities |
| Expiration | Credits can expire | Licenses tied to subscription lifecycle |
| Bundling | N/A | CM Instance bundles 3 Agent Seats |

---

## Database Schema Design

### New Tables

#### 1. `spike_license_pools` - Tenant's License Inventory

```php
Schema::create('spike_license_pools', function (Blueprint $table) {
    $table->id();
    $table->uuidMorphs('billable');              // Tenant (polymorphic)
    $table->string('license_type');              // 'cm_instance' or 'agent_seat'
    $table->unsignedInteger('included');         // From subscription plan
    $table->unsignedInteger('purchased');        // Additional purchased via Stripe
    $table->unsignedInteger('bundled')->default(0); // Derived from other licenses (agent seats from CM)
    $table->timestamps();

    $table->unique(['billable_type', 'billable_id', 'license_type'], 'license_pool_unique');
});
```

#### 2. `spike_license_allocations` - What's Using the Licenses

```php
Schema::create('spike_license_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('license_pool_id')->constrained('spike_license_pools')->cascadeOnDelete();
    $table->uuidMorphs('allocatable');           // ClientCompany or User
    $table->string('allocation_type');           // 'primary' or 'bundled'
    $table->foreignId('parent_allocation_id')   // For bundled seats, points to CM allocation
        ->nullable()
        ->constrained('spike_license_allocations')
        ->nullOnDelete();
    $table->string('notes')->nullable();
    $table->timestamps();

    $table->unique(['license_pool_id', 'allocatable_type', 'allocatable_id'], 'allocation_unique');
});
```

### Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              TENANT (Billable)                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      │ hasMany
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            spike_license_pools                              │
├─────────────────────────────────────────────────────────────────────────────┤
│ id │ billable_type │ billable_id │ license_type │ included │ purchased │ bundled │
├────┼───────────────┼─────────────┼──────────────┼──────────┼───────────┼─────────┤
│ 1  │ App\Tenant    │ uuid-123    │ cm_instance  │ 1        │ 2         │ 0       │
│ 2  │ App\Tenant    │ uuid-123    │ agent_seat   │ 0        │ 4         │ 9       │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      │ hasMany
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         spike_license_allocations                           │
├─────────────────────────────────────────────────────────────────────────────┤
│ id │ pool_id │ allocatable_type  │ allocatable_id │ type    │ parent_id │
├────┼─────────┼───────────────────┼────────────────┼─────────┼───────────┤
│ 1  │ 1       │ App\ClientCompany │ cc-uuid-1      │ primary │ NULL      │
│ 2  │ 1       │ App\ClientCompany │ cc-uuid-2      │ primary │ NULL      │
│ 3  │ 2       │ App\User          │ user-uuid-1    │ bundled │ 1         │
│ 4  │ 2       │ App\User          │ user-uuid-2    │ bundled │ 1         │
│ 5  │ 2       │ App\User          │ user-uuid-3    │ primary │ NULL      │
└─────────────────────────────────────────────────────────────────────────────┘

Pool Calculations:
- CM Instance Pool: total = included(1) + purchased(2) = 3, allocated = 2, available = 1
- Agent Seat Pool: total = bundled(9) + purchased(4) = 13, allocated = 3, available = 10
  - bundled = CM allocations(3) × 3 seats each = 9
```

### Stripe Subscription Items Integration

```
stripe_subscription_items
├── id: 1, stripe_price: 'price_pro_plan', quantity: 1 (base plan)
├── id: 2, stripe_price: 'price_cm_instance', quantity: 2 (purchased CM instances)
└── id: 3, stripe_price: 'price_agent_seat', quantity: 4 (purchased additional seats)
```

---

## Implementation Plan

### Phase 1: Core License Infrastructure

#### New Classes Structure

```
packages/spike-free/src/
├── Contracts/
│   └── LicenseAllocatable.php          # Interface for allocatable models
├── Licenses/
│   ├── LicenseType.php                 # License type definition (like CreditType)
│   ├── LicenseEntitlement.php          # Implements Providable for plan inclusion
│   ├── LicensePool.php                 # Eloquent model for pools
│   ├── LicenseAllocation.php           # Eloquent model for allocations
│   └── LicenseManager.php              # Core license operations
├── Traits/
│   └── ManagesLicenses.php             # Trait for billable model
├── Facades/
│   └── Licenses.php                    # Facade for LicenseManager
└── Actions/
    └── Licenses/
        ├── AllocateLicense.php         # Action to allocate a license
        ├── ReleaseLicense.php          # Action to release a license
        ├── PurchaseAdditionalLicenses.php
        └── SyncLicensePoolFromStripe.php
```

#### 1. LicenseType Class

```php
<?php

namespace Opcodes\Spike\Licenses;

class LicenseType
{
    public function __construct(
        public string $type,
        public string $name,
        public ?string $stripePriceId = null,
        public int $priceInCents = 0,
        public array $bundles = [],  // e.g., ['agent_seat' => 3]
    ) {}

    public static function make(string $type): static
    {
        $config = config("spike.license_types.{$type}");

        if (!$config) {
            throw new \InvalidArgumentException("Unknown license type: {$type}");
        }

        return new static(
            type: $type,
            name: $config['name'],
            stripePriceId: $config['stripe_price_id'] ?? null,
            priceInCents: $config['price_in_cents'] ?? 0,
            bundles: $config['bundles'] ?? [],
        );
    }

    public function bundlesLicenseType(string $type): bool
    {
        return isset($this->bundles[$type]);
    }

    public function getBundledQuantity(string $type): int
    {
        return $this->bundles[$type] ?? 0;
    }
}
```

#### 2. LicenseEntitlement Class (Providable)

```php
<?php

namespace Opcodes\Spike\Licenses;

use Opcodes\Spike\Contracts\Providable;
use Opcodes\Spike\Product;
use Opcodes\Spike\SubscriptionPlan;

class LicenseEntitlement implements Providable
{
    public function __construct(
        protected string $licenseType,
        protected int $quantity,
    ) {}

    public static function make(string $licenseType, int $quantity): static
    {
        return new static($licenseType, $quantity);
    }

    public static function __set_state(array $data): Providable
    {
        return new static($data['licenseType'], $data['quantity']);
    }

    public function key(): string
    {
        return "license-entitlement:{$this->licenseType}";
    }

    public function name(): string
    {
        return LicenseType::make($this->licenseType)->name;
    }

    public function icon(): ?string
    {
        return null;
    }

    public function toString(): string
    {
        return "{$this->quantity} {$this->name()}";
    }

    public function isSameProvidable(Providable $providable): bool
    {
        return $providable instanceof LicenseEntitlement
            && $this->licenseType === $providable->licenseType;
    }

    public function provideOnceFromProduct(Product $product, $billable): void
    {
        // Licenses are subscription-based, not one-time products
        throw new \BadMethodCallException('Licenses cannot be provided from products.');
    }

    public function provideMonthlyFromSubscriptionPlan(SubscriptionPlan $plan, $billable): void
    {
        app(LicenseManager::class)
            ->billable($billable)
            ->type($this->licenseType)
            ->setIncluded($this->quantity);
    }

    public function getLicenseType(): string
    {
        return $this->licenseType;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
```

#### 3. LicensePool Model

```php
<?php

namespace Opcodes\Spike\Licenses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LicensePool extends Model
{
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
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LicenseAllocation::class, 'license_pool_id');
    }

    public function licenseType(): LicenseType
    {
        return LicenseType::make($this->license_type);
    }

    // Pool calculations
    public function total(): int
    {
        return $this->included + $this->purchased + $this->bundled;
    }

    public function allocated(): int
    {
        return $this->allocations()->count();
    }

    public function available(): int
    {
        return max(0, $this->total() - $this->allocated());
    }

    public function hasAvailable(int $quantity = 1): bool
    {
        return $this->available() >= $quantity;
    }
}
```

#### 4. LicenseAllocation Model

```php
<?php

namespace Opcodes\Spike\Licenses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LicenseAllocation extends Model
{
    protected $table = 'spike_license_allocations';

    const TYPE_PRIMARY = 'primary';   // Directly allocated (purchased or included)
    const TYPE_BUNDLED = 'bundled';   // Bundled with another license (e.g., agent seats with CM)

    protected $fillable = [
        'license_pool_id',
        'allocatable_type',
        'allocatable_id',
        'allocation_type',
        'parent_allocation_id',
        'notes',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(LicensePool::class, 'license_pool_id');
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo('allocatable');
    }

    public function parentAllocation(): BelongsTo
    {
        return $this->belongsTo(LicenseAllocation::class, 'parent_allocation_id');
    }

    public function childAllocations(): HasMany
    {
        return $this->hasMany(LicenseAllocation::class, 'parent_allocation_id');
    }

    public function isPrimary(): bool
    {
        return $this->allocation_type === self::TYPE_PRIMARY;
    }

    public function isBundled(): bool
    {
        return $this->allocation_type === self::TYPE_BUNDLED;
    }
}
```

#### 5. LicenseManager Class

```php
<?php

namespace Opcodes\Spike\Licenses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Opcodes\Spike\Contracts\SpikeBillable;

class LicenseManager
{
    protected ?Model $billable = null;
    protected ?string $licenseType = null;

    public function billable(SpikeBillable|Model $billable): static
    {
        $this->billable = $billable;
        return $this;
    }

    public function type(string $licenseType): static
    {
        $this->licenseType = $licenseType;
        return $this;
    }

    protected function getBillable(): Model
    {
        if (!$this->billable) {
            throw new \RuntimeException('Billable not set on LicenseManager');
        }
        return $this->billable;
    }

    protected function getLicenseType(): string
    {
        if (!$this->licenseType) {
            throw new \RuntimeException('License type not set on LicenseManager');
        }
        return $this->licenseType;
    }

    /**
     * Get or create the license pool for the current billable and type
     */
    public function pool(): LicensePool
    {
        return LicensePool::firstOrCreate([
            'billable_type' => $this->getBillable()->getMorphClass(),
            'billable_id' => $this->getBillable()->getKey(),
            'license_type' => $this->getLicenseType(),
        ], [
            'included' => 0,
            'purchased' => 0,
            'bundled' => 0,
        ]);
    }

    public function total(): int
    {
        return $this->pool()->total();
    }

    public function allocated(): int
    {
        return $this->pool()->allocated();
    }

    public function available(): int
    {
        return $this->pool()->available();
    }

    public function hasAvailable(int $quantity = 1): bool
    {
        return $this->pool()->hasAvailable($quantity);
    }

    /**
     * Set the included licenses from subscription plan
     */
    public function setIncluded(int $quantity): void
    {
        $pool = $this->pool();
        $pool->included = $quantity;
        $pool->save();

        $this->recalculateBundledLicenses();
        $this->clearCache();
    }

    /**
     * Set the purchased licenses (synced from Stripe)
     */
    public function setPurchased(int $quantity): void
    {
        $pool = $this->pool();
        $pool->purchased = $quantity;
        $pool->save();

        $this->recalculateBundledLicenses();
        $this->clearCache();
    }

    /**
     * Allocate a license to an entity
     */
    public function allocate(
        Model $allocatable,
        string $allocationType = LicenseAllocation::TYPE_PRIMARY,
        ?LicenseAllocation $parentAllocation = null,
        ?string $notes = null
    ): LicenseAllocation {
        $pool = $this->pool();

        if (!$pool->hasAvailable()) {
            throw new InsufficientLicensesException(
                "No available {$this->getLicenseType()} licenses"
            );
        }

        // Check if already allocated
        $existing = $pool->allocations()
            ->where('allocatable_type', $allocatable->getMorphClass())
            ->where('allocatable_id', $allocatable->getKey())
            ->first();

        if ($existing) {
            throw new LicenseAlreadyAllocatedException(
                "License already allocated to this entity"
            );
        }

        $allocation = LicenseAllocation::create([
            'license_pool_id' => $pool->id,
            'allocatable_type' => $allocatable->getMorphClass(),
            'allocatable_id' => $allocatable->getKey(),
            'allocation_type' => $allocationType,
            'parent_allocation_id' => $parentAllocation?->id,
            'notes' => $notes,
        ]);

        // If this license type bundles other licenses, update those pools
        $this->handleBundledLicensesOnAllocate();
        $this->clearCache();

        return $allocation;
    }

    /**
     * Release a license from an entity
     */
    public function release(Model $allocatable): bool
    {
        $pool = $this->pool();

        $allocation = $pool->allocations()
            ->where('allocatable_type', $allocatable->getMorphClass())
            ->where('allocatable_id', $allocatable->getKey())
            ->first();

        if (!$allocation) {
            return false;
        }

        // If this allocation has child allocations (bundled seats), handle them
        $this->handleBundledLicensesOnRelease($allocation);

        $allocation->delete();
        $this->clearCache();

        return true;
    }

    /**
     * Recalculate bundled licenses based on allocations
     * Called when CM instances are allocated/released
     */
    protected function recalculateBundledLicenses(): void
    {
        $licenseType = LicenseType::make($this->getLicenseType());

        foreach ($licenseType->bundles as $bundledType => $quantityPerLicense) {
            $pool = $this->pool();
            $allocatedCount = $pool->allocated();

            // Update the bundled pool
            $bundledPool = LicensePool::firstOrCreate([
                'billable_type' => $this->getBillable()->getMorphClass(),
                'billable_id' => $this->getBillable()->getKey(),
                'license_type' => $bundledType,
            ], [
                'included' => 0,
                'purchased' => 0,
                'bundled' => 0,
            ]);

            // Bundled = (total CM instances in pool) × quantity per license
            // NOT allocated count - bundled seats come from having the CM license, not using it
            $bundledPool->bundled = $pool->total() * $quantityPerLicense;
            $bundledPool->save();
        }
    }

    protected function handleBundledLicensesOnAllocate(): void
    {
        // Bundled licenses are calculated at pool level, not allocation level
        $this->recalculateBundledLicenses();
    }

    protected function handleBundledLicensesOnRelease(LicenseAllocation $allocation): void
    {
        // Release any child allocations (bundled seats tied to this CM instance)
        foreach ($allocation->childAllocations as $childAllocation) {
            $childAllocation->delete();
        }

        $this->recalculateBundledLicenses();
    }

    public function clearCache(): void
    {
        $cacheKey = $this->getCacheKey();
        Cache::forget($cacheKey);
    }

    protected function getCacheKey(): string
    {
        return sprintf(
            'spike.licenses.%s.%s.%s',
            $this->getBillable()->getMorphClass(),
            $this->getBillable()->getKey(),
            $this->getLicenseType()
        );
    }
}
```

### Phase 2: Configuration Structure

#### Spike Configuration Extension

```php
// config/spike.php

return [
    // ... existing config ...

    /*
    |--------------------------------------------------------------------------
    | License Types
    |--------------------------------------------------------------------------
    |
    | Define the license types available for purchase. Each license type
    | can optionally bundle other license types.
    |
    */
    'license_types' => [
        'cm_instance' => [
            'name' => 'Conversational Manager Instance',
            'stripe_price_id' => env('STRIPE_PRICE_CM_INSTANCE', 'price_cm_instance_monthly'),
            'price_in_cents' => 3000, // $30/month
            'bundles' => [
                'agent_seat' => 3, // Each CM instance includes 3 agent seats
            ],
        ],
        'agent_seat' => [
            'name' => 'Agent Seat',
            'stripe_price_id' => env('STRIPE_PRICE_AGENT_SEAT', 'price_agent_seat_monthly'),
            'price_in_cents' => 1000, // $10/month for additional seats
            'bundles' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans with License Entitlements
    |--------------------------------------------------------------------------
    */
    'subscriptions' => [
        [
            'id' => 'starter',
            'name' => 'Starter',
            'price_in_cents_monthly' => 4900,
            'provides_monthly' => [
                \Opcodes\Spike\CreditAmount::make(2000),
            ],
            'license_entitlements' => [
                // No CM instances included - must purchase separately
            ],
            'features' => [
                '2,000 message credits/month',
                '1 Voiceflow Project',
                'Purchase CM instances separately',
            ],
        ],
        [
            'id' => 'pro',
            'name' => 'Pro',
            'price_in_cents_monthly' => 9900,
            'provides_monthly' => [
                \Opcodes\Spike\CreditAmount::make(10000),
            ],
            'license_entitlements' => [
                \Opcodes\Spike\Licenses\LicenseEntitlement::make('cm_instance', 1),
                // Note: 3 agent seats are bundled automatically with the CM instance
            ],
            'features' => [
                '10,000 message credits/month',
                '5 Voiceflow Projects',
                '1 CM Instance included (3 agent seats)',
                'Purchase additional CM instances & seats',
            ],
        ],
        [
            'id' => 'enterprise',
            'name' => 'Enterprise',
            'price_in_cents_monthly' => 29900,
            'provides_monthly' => [
                \Opcodes\Spike\CreditAmount::make(50000),
            ],
            'license_entitlements' => [
                \Opcodes\Spike\Licenses\LicenseEntitlement::make('cm_instance', 5),
            ],
            'features' => [
                '50,000 message credits/month',
                'Unlimited Voiceflow Projects',
                '5 CM Instances included (15 agent seats)',
                'Purchase additional CM instances & seats',
            ],
        ],
    ],
];
```

### Phase 3: Stripe Integration

#### PaymentGateway Extensions

```php
// Add to src/Stripe/PaymentGateway.php

/**
 * Add or update a license subscription item
 */
public function updateLicenseQuantity(string $licenseType, int $quantity): void
{
    $subscription = $this->getSubscription();

    if (!$subscription || !$subscription->hasPaymentCard()) {
        throw new \RuntimeException('No active subscription with payment method');
    }

    $licenseConfig = LicenseType::make($licenseType);
    $priceId = $licenseConfig->stripePriceId;

    if (!$priceId) {
        throw new \InvalidArgumentException("No Stripe price configured for license type: {$licenseType}");
    }

    // Find existing subscription item for this license type
    $existingItem = $subscription->items->firstWhere('stripe_price', $priceId);

    if ($quantity <= 0 && $existingItem) {
        // Remove the subscription item
        $existingItem->delete();
        return;
    }

    if ($existingItem) {
        // Update quantity
        $existingItem->updateQuantity($quantity);
    } else {
        // Add new subscription item
        $subscription->addPrice($priceId, $quantity);
    }
}

/**
 * Get current license quantities from Stripe subscription
 */
public function getLicenseQuantitiesFromStripe(): array
{
    $subscription = $this->getSubscription();

    if (!$subscription) {
        return [];
    }

    $quantities = [];
    $licenseTypes = config('spike.license_types', []);

    foreach ($licenseTypes as $type => $config) {
        $priceId = $config['stripe_price_id'] ?? null;

        if ($priceId) {
            $item = $subscription->items->firstWhere('stripe_price', $priceId);
            $quantities[$type] = $item ? $item->quantity : 0;
        }
    }

    return $quantities;
}
```

#### Webhook Listener Extension

```php
// Extend src/Stripe/Listeners/StripeWebhookListener.php

protected function handleSubscriptionUpdated(WebhookHandled $event): void
{
    // ... existing credit handling ...

    // Step 3 - Sync license pools from subscription items
    $this->syncLicensePoolsFromSubscription($billable, $subscription);
}

protected function syncLicensePoolsFromSubscription($billable, $subscription): void
{
    $licenseTypes = config('spike.license_types', []);

    foreach ($licenseTypes as $type => $config) {
        $priceId = $config['stripe_price_id'] ?? null;

        if (!$priceId) {
            continue;
        }

        $item = $subscription->items->firstWhere('stripe_price', $priceId);
        $purchasedQuantity = $item ? $item->quantity : 0;

        // Subtract included licenses from plan to get purchased count
        $plan = Spike::findSubscriptionPlan($subscription->getPriceId(), $billable);
        $includedQuantity = $this->getIncludedLicensesFromPlan($plan, $type);

        $actualPurchased = max(0, $purchasedQuantity - $includedQuantity);

        app(LicenseManager::class)
            ->billable($billable)
            ->type($type)
            ->setPurchased($actualPurchased);
    }
}

protected function getIncludedLicensesFromPlan(?SubscriptionPlan $plan, string $licenseType): int
{
    if (!$plan) {
        return 0;
    }

    foreach ($plan->license_entitlements ?? [] as $entitlement) {
        if ($entitlement instanceof LicenseEntitlement
            && $entitlement->getLicenseType() === $licenseType) {
            return $entitlement->getQuantity();
        }
    }

    return 0;
}
```

### Phase 4: FlowBridge Connect Integration

#### Integration Points

```php
// In FlowBridge Connect application

// 1. When enabling Chatwoot for a ClientCompany
public function enableChatwootForCompany(ClientCompany $company): void
{
    $tenant = $company->tenant;

    // Check license availability
    $licenseManager = app(LicenseManager::class)
        ->billable($tenant)
        ->type('cm_instance');

    if (!$licenseManager->hasAvailable()) {
        throw new InsufficientLicensesException(
            'No CM Instance licenses available. Please purchase additional licenses.'
        );
    }

    // Allocate the license
    $allocation = $licenseManager->allocate($company, 'primary', null,
        "Enabled Chatwoot for {$company->name}"
    );

    // Create Chatwoot account
    $this->chatwootService->createAccountForCompany($company);

    // Store allocation reference
    $company->update(['cm_license_allocation_id' => $allocation->id]);
}

// 2. When adding an agent to Chatwoot
public function addAgentToCompany(User $user, ClientCompany $company): void
{
    $tenant = $company->tenant;

    // Check agent seat availability
    $licenseManager = app(LicenseManager::class)
        ->billable($tenant)
        ->type('agent_seat');

    if (!$licenseManager->hasAvailable()) {
        throw new InsufficientLicensesException(
            'No Agent Seat licenses available. Please purchase additional seats.'
        );
    }

    // Get the CM allocation for this company (for bundled seat tracking)
    $cmAllocation = LicenseAllocation::find($company->cm_license_allocation_id);

    // Allocate agent seat
    $allocation = $licenseManager->allocate(
        $user,
        'primary', // or 'bundled' if tracking which CM instance it came from
        null,
        "Agent access for {$company->name}"
    );

    // Add user to Chatwoot
    $this->chatwootService->addAgentToAccount($user, $company);
}

// 3. When disabling Chatwoot for a ClientCompany
public function disableChatwootForCompany(ClientCompany $company): void
{
    $tenant = $company->tenant;

    // Release the CM license (this will also release bundled agent seats)
    app(LicenseManager::class)
        ->billable($tenant)
        ->type('cm_instance')
        ->release($company);

    // Remove Chatwoot account
    $this->chatwootService->deleteAccountForCompany($company);

    $company->update(['cm_license_allocation_id' => null]);
}
```

#### ManagesLicenses Trait for Tenant Model

```php
// Add to Tenant model in FlowBridge Connect

use Opcodes\Spike\Traits\ManagesLicenses;

class Tenant extends Model
{
    use ManagesLicenses;

    public function cmInstancePool(): LicensePool
    {
        return $this->licenses()->type('cm_instance')->pool();
    }

    public function agentSeatPool(): LicensePool
    {
        return $this->licenses()->type('agent_seat')->pool();
    }

    public function availableCmInstances(): int
    {
        return $this->licenses()->type('cm_instance')->available();
    }

    public function availableAgentSeats(): int
    {
        return $this->licenses()->type('agent_seat')->available();
    }

    public function totalAgentSeats(): int
    {
        return $this->licenses()->type('agent_seat')->total();
    }
}
```

---

## Edge Cases & Considerations

### 1. CM Instance Deallocation - Bundled Seat Handling

**Question**: When a CM Instance is deallocated, what happens to the 3 bundled agent seats?

**Recommended Behavior**:

```
Scenario: Tenant has 2 CM Instances (6 bundled seats) + 2 purchased seats = 8 total
         Currently using 7 agent seats

Action: Disable CM for ClientCompany A (releases 1 CM Instance)

Result:
- CM Instances: 2 → 1 (3 bundled seats remain)
- Total seats: 3 bundled + 2 purchased = 5 seats
- Currently allocated: 7 seats
- OVER-ALLOCATED by 2 seats!

Options:
A) Block deallocation until agent seats are released
B) Allow deallocation, mark excess seats as "grace period"
C) Auto-release oldest/specific agent allocations

RECOMMENDED: Option A - Block with clear error message
```

**Implementation**:

```php
public function release(Model $allocatable): bool
{
    $pool = $this->pool();
    $allocation = $pool->allocations()
        ->where('allocatable_type', $allocatable->getMorphClass())
        ->where('allocatable_id', $allocatable->getKey())
        ->first();

    if (!$allocation) {
        return false;
    }

    // Check if releasing this would cause over-allocation in bundled pools
    $licenseType = LicenseType::make($this->getLicenseType());

    foreach ($licenseType->bundles as $bundledType => $quantityPerLicense) {
        $bundledPool = LicensePool::where([
            'billable_type' => $this->getBillable()->getMorphClass(),
            'billable_id' => $this->getBillable()->getKey(),
            'license_type' => $bundledType,
        ])->first();

        if ($bundledPool) {
            $newBundledTotal = $bundledPool->bundled - $quantityPerLicense;
            $newTotal = $bundledPool->included + $bundledPool->purchased + $newBundledTotal;

            if ($bundledPool->allocated() > $newTotal) {
                throw new OverAllocationException(
                    "Cannot release CM Instance: {$bundledPool->allocated()} agent seats are allocated, " .
                    "but only {$newTotal} would remain. Please release " .
                    ($bundledPool->allocated() - $newTotal) . " agent seat(s) first."
                );
            }
        }
    }

    // Safe to release
    $this->handleBundledLicensesOnRelease($allocation);
    $allocation->delete();
    $this->clearCache();

    return true;
}
```

### 2. Plan Downgrade with Over-Allocated Licenses

**Scenario**: Tenant on Enterprise (5 CM included) downgrades to Pro (1 CM included)

```
Before: 5 included + 0 purchased = 5 CM Instances, 4 allocated
After:  1 included + 0 purchased = 1 CM Instance, 4 allocated (OVER!)

Options:
A) Block downgrade until licenses released
B) Auto-convert excess to purchased (charge difference)
C) Grace period with forced release after X days

RECOMMENDED: Option A for simplicity, with clear messaging
```

**Implementation in SubscriptionManager**:

```php
public function canSwitchToPlan(SubscriptionPlan $newPlan): array
{
    $issues = [];
    $billable = $this->getBillable();

    // Check each license type
    foreach (config('spike.license_types') as $type => $config) {
        $pool = app(LicenseManager::class)
            ->billable($billable)
            ->type($type)
            ->pool();

        $currentIncluded = $pool->included;
        $newIncluded = $this->getIncludedFromPlan($newPlan, $type);
        $allocated = $pool->allocated();
        $purchased = $pool->purchased;

        $newTotal = $newIncluded + $purchased + $pool->bundled;

        if ($allocated > $newTotal) {
            $issues[] = [
                'type' => $type,
                'message' => "You have {$allocated} {$config['name']} licenses allocated, " .
                            "but the new plan only supports {$newTotal}. " .
                            "Please release " . ($allocated - $newTotal) . " license(s) first.",
                'release_required' => $allocated - $newTotal,
            ];
        }
    }

    return $issues;
}
```

### 3. Proration for Mid-Cycle License Changes

**Stripe handles this automatically** when using subscription items with quantities:

```php
// Adding 2 CM instances mid-cycle
$subscription->addPrice('price_cm_instance', 2, [
    'proration_behavior' => 'create_prorations', // Default
]);

// Stripe will:
// 1. Calculate remaining days in billing period
// 2. Charge prorated amount for new licenses
// 3. Full amount on next renewal
```

**Configuration option**:

```php
// config/spike.php
'licenses' => [
    'proration_behavior' => 'create_prorations', // or 'none', 'always_invoice'
],
```

### 4. Bundled Seats Calculation Edge Cases

**Question**: Are bundled seats calculated from total CM pool or allocated CM instances?

**Answer**: From **total CM pool** (included + purchased), not allocated count.

```
Rationale:
- Tenant pays for 3 CM instances = 9 bundled seats available
- Even if only 1 CM is allocated to a company, all 9 seats are available
- This is more generous and simpler to understand

Alternative (stricter):
- Bundled seats only available when CM is allocated
- More complex tracking, less user-friendly
```

### 5. License Allocation Audit Trail

For compliance and debugging, track all allocation changes:

```php
Schema::create('spike_license_audit_log', function (Blueprint $table) {
    $table->id();
    $table->foreignId('license_pool_id')->constrained('spike_license_pools');
    $table->foreignId('allocation_id')->nullable();
    $table->string('action'); // 'allocated', 'released', 'pool_updated'
    $table->json('before_state')->nullable();
    $table->json('after_state')->nullable();
    $table->uuidMorphs('actor'); // User who made the change
    $table->string('reason')->nullable();
    $table->timestamps();
});
```

---

## Risks & Challenges

### Technical Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Stripe sync failures | Medium | High | Webhook retry logic, manual sync command |
| Race conditions on allocation | Low | Medium | Database transactions, optimistic locking |
| Pool calculation errors | Low | High | Comprehensive test coverage, audit logging |
| Plan switching edge cases | Medium | Medium | Thorough validation before switch |

### Business Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Pricing confusion | Medium | Medium | Clear UI, documentation, tooltips |
| Over-allocation disputes | Low | High | Audit trail, clear error messages |
| Downgrade friction | Medium | Low | Grace periods, clear warnings |
| Bundling complexity | Medium | Medium | Simple "X seats included" messaging |

### Operational Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Support ticket increase | High | Medium | Self-service UI, clear documentation |
| Billing disputes | Low | Medium | Detailed invoices, audit trail |
| Migration complexity | Medium | Medium | Phased rollout, grandfathering |

---

## Estimated Effort

### Development Effort Breakdown

| Component | Effort | Description |
|-----------|--------|-------------|
| **Core License Infrastructure** | 3-4 days | |
| - LicenseType, LicenseEntitlement | 0.5 day | Follow CreditType/CreditAmount patterns |
| - LicensePool, LicenseAllocation models | 1 day | Eloquent models, relationships |
| - LicenseManager | 1.5 days | Core allocation/release logic |
| - ManagesLicenses trait | 0.5 day | Billable integration |
| - Database migrations | 0.5 day | Tables, indexes |
| **Stripe Integration** | 2-3 days | |
| - PaymentGateway extensions | 1 day | Quantity management methods |
| - Webhook listener updates | 1 day | License sync on subscription events |
| - Subscription item management | 1 day | Add/remove/update quantities |
| **Configuration & Setup** | 1 day | |
| - Config structure | 0.5 day | spike.php extensions |
| - Stripe product/price setup | 0.5 day | Dashboard configuration |
| **FlowBridge Integration** | 2-3 days | |
| - ClientCompany CM allocation | 1 day | Enable/disable Chatwoot |
| - User agent seat allocation | 1 day | Add/remove agents |
| - Tenant dashboard/UI | 1 day | License pool visibility |
| **Testing** | 2-3 days | |
| - Unit tests | 1 day | Manager, models |
| - Integration tests | 1 day | Stripe webhooks, allocation flows |
| - Edge case tests | 1 day | Downgrade, over-allocation |
| **Documentation** | 1 day | |
| - API documentation | 0.5 day | |
| - User documentation | 0.5 day | |

### Total Estimated Effort

| Phase | Effort |
|-------|--------|
| Core Infrastructure | 3-4 days |
| Stripe Integration | 2-3 days |
| Configuration | 1 day |
| FlowBridge Integration | 2-3 days |
| Testing | 2-3 days |
| Documentation | 1 day |
| **Total** | **11-15 days** |

### Recommended Phasing

**Phase 1 (MVP)**: Core infrastructure + basic allocation (5-6 days)
- License pools and allocations working
- Manual license management via Filament
- No Stripe integration yet

**Phase 2 (Billing)**: Stripe integration (3-4 days)
- Subscription item quantities
- Webhook sync
- Proration handling

**Phase 3 (Polish)**: UI/UX + edge cases (3-5 days)
- Self-service purchase UI
- Plan switching validation
- Comprehensive testing

---

## Appendix: API Reference

### LicenseManager Facade

```php
use Opcodes\Spike\Facades\Licenses;

// Get pool information
Licenses::billable($tenant)->type('cm_instance')->total();      // 5
Licenses::billable($tenant)->type('cm_instance')->allocated();  // 3
Licenses::billable($tenant)->type('cm_instance')->available();  // 2

// Check availability
Licenses::billable($tenant)->type('agent_seat')->hasAvailable(2); // true/false

// Allocate a license
$allocation = Licenses::billable($tenant)
    ->type('cm_instance')
    ->allocate($clientCompany, 'primary', null, 'Enabled CM');

// Release a license
Licenses::billable($tenant)->type('cm_instance')->release($clientCompany);

// Update pool quantities (usually done via webhooks)
Licenses::billable($tenant)->type('cm_instance')->setIncluded(3);
Licenses::billable($tenant)->type('agent_seat')->setPurchased(5);
```

### Events

```php
// Dispatched events for integration
LicenseAllocated::class      // When a license is allocated
LicenseReleased::class       // When a license is released
LicensePoolUpdated::class    // When pool quantities change
InsufficientLicenses::class  // When allocation fails due to no availability
```

### Exceptions

```php
InsufficientLicensesException::class    // No licenses available
LicenseAlreadyAllocatedException::class // Entity already has this license
OverAllocationException::class          // Would cause over-allocation
InvalidLicenseTypeException::class      // Unknown license type
```
```


