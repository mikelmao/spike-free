<?php

namespace Opcodes\Spike;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Opcodes\Spike\Actions\VerifyBillableUsesTrait;
use Opcodes\Spike\Contracts\ProcessCartPaymentContract;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\Facades\PaymentGateway;

class SpikeManager
{
    /**
     * Spike's resolvers for different billable models.
     * @var array
     */
    static array $resolvers = [];

    /**
     * Spike's authorization callbacks for different billable models.
     * @var array
     */
    static array $authorizationChecks = [];

    /**
     * Spike's product resolvers for different billable models.
     * @var array
     */
    static array $productResolvers = [];

    /**
     * Spike's subscription plan resolvers for different billable models.
     * @var array
     */
    static array $subscriptionResolvers = [];

    /**
     * Spike's avatar resolvers for different billable models.
     * @var array
     */
    static array $avatarResolvers = [];

    /**
     * Spike's cancellation offer resolvers for different billable models.
     * @var array
     */
    static array $cancellationOfferResolvers = [];

    /**
     * Spike's product purchase thank you page redirection URL.
     * @var array
     */
    static array $redirectAfterProductPurchaseTo = ['url' => null, 'delay' => 0];

    /**
     * Spike's subscription thank you page redirection URL.
     */
    static array $redirectAfterSubscriptionTo = ['url' => null, 'delay' => 0];

    /**
     * The license pool model class name.
     */
    protected static string $licensePoolModel = LicensePool::class;

    /**
     * The license allocation model class name.
     */
    protected static string $licenseAllocationModel = LicenseAllocation::class;

    /**
     * @var string|null
     */
    protected ?string $billableClass = null;

    public function __construct(?string $billableClass = null)
    {
        $this->billableClass = $billableClass;
    }

    public function paymentProvider(): PaymentProvider
    {
        return SpikeServiceProvider::paymentProvider();
    }

    public function resolveAvatarUsing(callable $callback): void
    {
        self::$avatarResolvers[$this->getBillableClass()] = $callback;
    }

    public function resolveAvatar($billableInstance = null): ?string
    {
        $billableClass = $billableInstance ? get_class($billableInstance) : $this->getBillableClass();

        if (empty($billableInstance)) {
            $billableInstance = $this->resolve();
        }

        if (
            isset(self::$avatarResolvers[$billableClass])
            && is_callable(self::$avatarResolvers[$billableClass])
        ) {
            return self::$avatarResolvers[$billableClass]($billableInstance);
        }

        return "https://www.gravatar.com/avatar/".md5(strtolower(trim($billableInstance->spikeEmail()))) . "?s=80";
    }

    public function processCartPaymentUsing(?string $callback = null): void
    {
        app()->singleton(ProcessCartPaymentContract::class, $callback);
    }

    public function clearCustomResolvers(): void
    {
        static::$productResolvers = [];
        static::$subscriptionResolvers = [];
        static::$authorizationChecks = [];
        static::$avatarResolvers = [];
        static::$cancellationOfferResolvers = [];
        static::$resolvers = [];
    }

    public function billable(string $billable): static
    {
        return new static($billable);
    }

    public function getBillableClass(): string
    {
        return $this->billableClass ?? config('spike.billable_models.0', 'default');
    }

    public function hasResolver(?string $billableClass = null): bool
    {
        $billableClass = $billableClass ?? $this->getBillableClass();

        return isset(self::$resolvers[$billableClass])
            && is_callable(self::$resolvers[$billableClass]);
    }

    /**
     * @param callable|null $callback
     * @return null
     * @throws \Exception
     */
    public function resolve(?callable $callback = null)
    {
        $billableClass = $this->getBillableClass();

        if (!is_null($callback)) {
            self::$resolvers[$billableClass] = $callback;

            return $this;
        } elseif ($this->hasResolver($billableClass)) {
            $billable = self::$resolvers[$billableClass](request());

            if (!is_null($billable)) {
                app(VerifyBillableUsesTrait::class)->handle($billable);
            }

            return $billable;
        }

        return null;
    }

    /**
     * @throws AuthorizationException|\Exception
     */
    public function authorize(?callable $callback = null): void
    {
        $billableClass = $this->getBillableClass();

        if (!is_null($callback)) {
            self::$authorizationChecks[$billableClass] = $callback;
        } elseif (isset(self::$authorizationChecks[$billableClass])) {
            $billable = $this->resolve();
            $authorized = self::$authorizationChecks[$billableClass]($billable, request());

            if (!$authorized) {
                throw new AuthorizationException();
            }
        }
    }

    public function resolveProductsUsing(callable $callback): void
    {
        self::$productResolvers[$this->getBillableClass()] = $callback;
    }

    /**
     * @param SpikeBillable|Model|null $billableInstance
     * @return Collection|Product[]
     */
    public function products($billableInstance = null, bool $includeArchived = false)
    {
        if ($billableInstance) {
            $billableClass = get_class($billableInstance);
        } else {
            try {
                $billableInstance = $this->resolve();
            } catch (\Exception) {
                $billableInstance = null;
            }

            $billableClass = $this->getBillableClass();
        }

        $finalProducts = collect(config('spike.products', []) ?? [])
            ->map(fn ($productConfig) => Product::fromArray($productConfig));

        if (isset(self::$productResolvers[$billableClass])) {
            $finalProducts = collect(
                self::$productResolvers[$billableClass]($billableInstance, $finalProducts)
            )->map(function ($product) {
                if ($product instanceof Product) {
                    return $product;
                }

                return Product::fromArray($product);
            });
        }

        if (! $includeArchived) {
            $finalProducts = $finalProducts->filter(fn (Product $product) => ! $product->archived);
        }

        return $finalProducts;
    }

    // TODO: this method is not consistent with the findSubscriptionPlan() method.
    // It should take in the payment_provider_price_id instead of the id.
    public function findProduct($id, $billableInstance = null, bool $includeArchived = false)
    {
        return $this->products($billableInstance, $includeArchived)
            ->firstWhere('id', '=', $id);
    }

    public function productsAvailable($billableInstance = null): bool
    {
        return $this->products($billableInstance)->isNotEmpty();
    }

    public function redirectAfterProductPurchaseTo(string|callable|null $url = null, int $delayInSeconds = 0): void
    {
        self::$redirectAfterProductPurchaseTo['url'] = $url;
        self::$redirectAfterProductPurchaseTo['delay'] = $delayInSeconds;
    }

    public function getRedirectAfterProductPurchaseTo(): array
    {
        return self::$redirectAfterProductPurchaseTo;
    }

    public function redirectAfterSubscriptionTo(string|callable|null $url = null, int $delayInSeconds = 0): void
    {
        self::$redirectAfterSubscriptionTo['url'] = $url;
        self::$redirectAfterSubscriptionTo['delay']  = $delayInSeconds;
    }

    public function getRedirectAfterSubscriptionTo(): array
    {
        return self::$redirectAfterSubscriptionTo;
    }

    public function resolveSubscriptionPlansUsing(callable $callback)
    {
        self::$subscriptionResolvers[$this->getBillableClass()] = $callback;
    }

    /**
     * @param SpikeBillable|Model|null $billableInstance
     * @param bool $includeArchived
     * @return Collection|SubscriptionPlan[]
     */
    public function subscriptionPlans($billableInstance = null, bool $includeArchived = false)
    {
        $plans = [];
        $hasFreePlan = false;

        if ($billableInstance) {
            $billableClass = get_class($billableInstance);
        } else {
            try {
                $billableInstance = $this->resolve();
            } catch (\Exception) {
                $billableInstance = null;
            }

            $billableClass = $this->getBillableClass();
        }

        foreach ((config('spike.subscriptions', []) ?: []) as $config) {
            $monthlyPlan = SubscriptionPlan::fromArray($config);
            $yearlyPlan = SubscriptionPlan::fromArray($config, yearly: true);

            if ($monthlyPlan->isFree() && $yearlyPlan->isFree()) {
                $hasFreePlan = true;
                $plans[] = $monthlyPlan;
                $plans[] = $yearlyPlan;
            } else {
                if ($monthlyPlan->isPaid()) {
                    $plans[] = $monthlyPlan;
                }

                if ($yearlyPlan->isPaid()) {
                    $plans[] = $yearlyPlan;
                }
            }
        }

        if (!empty($plans) && !$hasFreePlan) {
            $plans = array_merge([
                SubscriptionPlan::defaultFreePlan(),
                SubscriptionPlan::defaultFreePlan(yearly: true),
            ], $plans);
        }

        if ($billableInstance) {
            $paymentGateway = PaymentGateway::billable($billableInstance);
            $subscription = $paymentGateway->getSubscription();

            foreach ($plans as $plan) {
                if ($plan->isFree() && $plan->isMonthly() && (!$subscription || $subscription->onGracePeriod())) {
                    $plan->current = true;
                } elseif ($plan->isPaid()) {
                    if ($paymentGateway->subscribed($plan)) {
                        $plan->current = !$subscription->onGracePeriod();
                        $plan->ends_at = $subscription->ends_at;
                    } elseif ($subscription && $subscription->hasPriceId($plan->payment_provider_price_id)) {
                        $plan->current = true;
                        $plan->past_due = $subscription->isPastDue();
                        $plan->ends_at = $subscription->ends_at;
                    }

                    if ($plan->current && $subscription->hasPromotionCode()) {
                        $plan->withPromotionCode($subscription->promotionCode());
                    }
                }
            }
        }

        if (isset(self::$subscriptionResolvers[$billableClass])) {
            $paymentGateway = $paymentGateway ?? null;

            $plans = collect(
                self::$subscriptionResolvers[$billableClass]($billableInstance, collect($plans))
            )->map(function ($subscriptionPlan) use ($paymentGateway) {
                if ($subscriptionPlan instanceof SubscriptionPlan) {
                    $plan = $subscriptionPlan;
                } else {
                    $plan = SubscriptionPlan::fromArray($subscriptionPlan);
                }

                if (isset($paymentGateway) && $plan->isPaid()) {
                    $subscription = $paymentGateway->getSubscription();

                    if ($paymentGateway->subscribed($plan)) {
                        $plan->current = !$subscription->onGracePeriod();
                        $plan->ends_at = $subscription->ends_at;
                    } elseif ($subscription && $subscription->hasPriceId($plan->payment_provider_price_id)) {
                        $plan->current = true;
                        $plan->past_due = $subscription->isPastDue();
                        $plan->ends_at = $subscription->ends_at;
                    }

                    if ($plan->current && $subscription->hasPromotionCode()) {
                        $plan->withPromotionCode($subscription->promotionCode());
                    }
                }

                return $plan;
            });
        }

        return collect($plans)->filter(function (SubscriptionPlan $plan) use ($includeArchived) {
            return $includeArchived || $plan->isActive() || ($plan->isCurrent() && $plan->isPaid());
        })->values();
    }

    /**
     * @return SubscriptionPlan[]|Collection
     */
    public function monthlySubscriptionPlans($billableInstance = null, bool $includeArchived = false)
    {
        return $this->subscriptionPlans($billableInstance, $includeArchived)
            ->filter(fn (SubscriptionPlan $plan) => $plan->isMonthly());
    }

    /**
     * @return SubscriptionPlan[]|Collection
     */
    public function yearlySubscriptionPlans($billableInstance = null, bool $includeArchived = false)
    {
        return $this->subscriptionPlans($billableInstance, $includeArchived)
            ->filter(fn (SubscriptionPlan $plan) => $plan->isYearly());
    }

    /**
     * @param string $payment_provider_price_id
     * @param null $billableInstance
     * @return SubscriptionPlan|null
     */
    public function findSubscriptionPlan(string $payment_provider_price_id, $billableInstance = null): ?SubscriptionPlan
    {
        $plan = Arr::first(
            $this->subscriptionPlans($billableInstance, true)->all(),
            function (SubscriptionPlan $plan) use ($payment_provider_price_id) {
                return $plan->payment_provider_price_id === $payment_provider_price_id
                    || $plan->id === $payment_provider_price_id;
            }
        );

        if (! $plan && $payment_provider_price_id === 'free') {
            return SubscriptionPlan::defaultFreePlan();
        }

        return $plan;
    }

    public function currentSubscriptionPlan($billableInstance = null): ?SubscriptionPlan
    {
        if (is_null($billableInstance)) {
            $billableInstance = self::resolve();
        }

        if (! is_null($billableInstance) && method_exists($billableInstance, 'currentSubscriptionPlan')) {
            return $billableInstance->currentSubscriptionPlan();
        }

        return Arr::first(
            $this->subscriptionPlans($billableInstance)->all(),
            fn ($plan) => $plan->current === true
        );
    }

    public function subscriptionPlansAvailable($billableInstance = null): bool
    {
        return $this->subscriptionPlans($billableInstance)->isNotEmpty();
    }

    public function stripeCheckoutEnabled(): bool
    {
        return $this->paymentProvider() === PaymentProvider::Stripe
            && config(
                'spike.stripe.checkout.enabled',
                config('spike.stripe_checkout.enabled', false)
            );
    }

    public function stripeAllowDiscounts(): bool
    {
        return $this->paymentProvider() === PaymentProvider::Stripe &&
            config(
                'spike.stripe.allow_discount_codes',
                config('spike.allow_discount_codes', false)
            );
    }

    public function stripePersistDiscountsWhenSwitchingPlans(): bool
    {
        return $this->paymentProvider() === PaymentProvider::Stripe && config(
                'spike.stripe.persist_discounts_when_switching_plans',
                config('spike.persist_discounts_when_switching_plans', false)
            );
    }

    public function stripeAllowIncompleteSubscriptionUpdates(): bool
    {
        return $this->paymentProvider() === PaymentProvider::Stripe && config(
                'spike.stripe.allow_incomplete_subscription_updates',
                false
            );
    }

    /**
     * Register a callback to resolve cancellation offers for a billable model.
     *
     * @param callable $callback The callback receives $billable and $defaultOffers as parameters
     * @return void
     */
    public function resolveCancellationOffersUsing(callable $callback): void
    {
        self::$cancellationOfferResolvers[$this->getBillableClass()] = $callback;
    }

    /**
     * Get cancellation offers for a billable model.
     *
     * @param mixed|Model|null $billableInstance
     * @return array|\Illuminate\Support\Collection
     */
    public function cancellationOffers($billableInstance = null)
    {
        if ($billableInstance) {
            $billableClass = get_class($billableInstance);
        } else {
            try {
                $billableInstance = $this->resolve();
            } catch (\Exception) {
                $billableInstance = null;
            }

            $billableClass = $this->getBillableClass();
        }

        $configOffers = collect(config('spike.stripe.cancellation_offers', []));

        if (isset(self::$cancellationOfferResolvers[$billableClass])) {
            return collect(
                self::$cancellationOfferResolvers[$billableClass]($billableInstance, $configOffers)
            );
        }

        return $configOffers;
    }

    public function mandateDataFromRequest(): ?array
    {
        if (!app()->has('request')) {
            return null;
        }

        // Spike shouldn't resolve a user/team when outside of HTTP context, so this should be a safe
        // check to ensure this mandate data is coming from an online action, straight from the user.
        if (! $this->resolve()) {
            return null;
        }

        return [
            'customer_acceptance' => [
                'type' => 'online',
                'online' => [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]
            ]
        ];
    }

    /**
     * Set the license pool model class name.
     */
    public static function useLicensePoolModel(string $model): void
    {
        static::$licensePoolModel = $model;
    }

    /**
     * Get the license pool model class name.
     */
    public static function licensePoolModel(): string
    {
        return static::$licensePoolModel;
    }

    /**
     * Create a new license pool model instance.
     */
    public static function newLicensePoolModel(): LicensePool
    {
        return new static::$licensePoolModel;
    }

    /**
     * Set the license allocation model class name.
     */
    public static function useLicenseAllocationModel(string $model): void
    {
        static::$licenseAllocationModel = $model;
    }

    /**
     * Get the license allocation model class name.
     */
    public static function licenseAllocationModel(): string
    {
        return static::$licenseAllocationModel;
    }

    /**
     * Create a new license allocation model instance.
     */
    public static function newLicenseAllocationModel(): LicenseAllocation
    {
        return new static::$licenseAllocationModel;
    }
}
