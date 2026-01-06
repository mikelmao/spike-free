<?php

namespace Opcodes\Spike\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Coupon;
use Laravel\Cashier\PromotionCode;
use Opcodes\Spike\Cart;
use Opcodes\Spike\Contracts\PaymentGatewayContract;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\PaymentProvider;
use Opcodes\Spike\SubscriptionPlan;
use Opcodes\Spike\Traits\ScopedToBillable;
use Stripe\Exception\InvalidRequestException;

class PaymentGateway implements PaymentGatewayContract
{
    use ScopedToBillable;

    static string $subscriptionName = 'default';

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Stripe;
    }

    public function findBillable($customer_id)
    {
        return Cashier::findBillable($customer_id);
    }

    /**
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment
     */
    public function payForCart(Cart $cart): bool
    {
        $billable = $this->getBillable();

        // Validate currency consistency and determine cart currency
        $cartCurrency = $cart->validateAndDetermineCurrency();

        foreach ($cart->items as $item) {
            $product = $item->product();
            $billable->tabPrice($product->payment_provider_price_id, $item->quantity, [
                'currency' => $product->currency,
            ]);
        }

        $invoiceOptions = [
            'metadata' => [
                'spike_cart_id' => $cart->id,
            ],
        ];

        // Set currency if determined from products
        if ($cartCurrency) {
            $invoiceOptions['currency'] = $cartCurrency;
        }

        if ($cart->hasPromotionCode()) {
            $invoiceOptions['discounts'] = [
                ['coupon' => $cart->promotionCode()->coupon()->id],
            ];
        }

        return (bool) $billable->invoice($invoiceOptions);
    }



    /**
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment
     */
    public function invoiceAndPayItems(array $items, array $options = []): bool
    {
        $billable = $this->getBillable();

        foreach ($items as $priceId => $quantity) {
            $billable->tabPrice($priceId, $quantity);
        }

        $invoice = $billable->invoice($options);

        return ! is_null($invoice);
    }

    /**
     * @return SpikeSubscription|\Laravel\Cashier\Subscription|null
     * @throws \Exception
     */
    public function getSubscription(): ?SpikeSubscription
    {
        return $this->getBillable()->subscription(self::$subscriptionName);
    }

    public function getRenewalDate(): ?CarbonInterface
    {
        $subscription = $this->getSubscription();

        if (!$subscription || !$subscription->valid()) {
            return null;
        }

        return $subscription->renewalDate();
    }

    public function subscribed(?SubscriptionPlan $plan = null): bool
    {
        return $this->getBillable()->subscribed(
            self::$subscriptionName,
            optional($plan)->payment_provider_price_id
        );
    }

    /**
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment
     */
    public function createSubscription(SubscriptionPlan $plan, bool $requirePaymentCard = true): SpikeSubscription
    {
        if ($requirePaymentCard) {
            $billable = $this->getBillable();
            $promotionCode = $billable->stripePromotionCode();
            $mandateData = Spike::mandateDataFromRequest();

            $subscription = $billable
                ->newSubscription(self::$subscriptionName, $plan->payment_provider_price_id)
                ->withPaymentConfirmationOptions($mandateData ? [
                    'mandate_data' => $mandateData,
                ] : [])
                ->add([], $promotionCode ? [
                    'promotion_code' => $promotionCode->id,
                ] : []);

            $subscription->forceFill([
                'promotion_code_id' => $promotionCode?->id ?? null
            ])->save();

            /** @noinspection PhpIncompatibleReturnTypeInspection */
            return $subscription;
        }

        $subscription = $this->getBillable()->subscriptions()->create([
            'type' => self::$subscriptionName,
            'stripe_id' => '',
            'stripe_status' => 'active',
            'stripe_price' => $plan->payment_provider_price_id,
            'quantity' => 1,
            'ends_at' => null,
            'renews_at' => $plan->isYearly() ? now()->addYear() : now()->addMonthNoOverflow(),
        ]);

        $subscription->items()->create([
            'stripe_id' => '',
            'stripe_product' => '',
            'stripe_price' => $plan->payment_provider_price_id,
            'quantity' => 1,
        ]);

        return $subscription;
    }

    /**
     * @throws \Laravel\Cashier\Exceptions\SubscriptionUpdateFailure
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment
     * @throws \Stripe\Exception\CardException thrown if using `error_if_incomplete` payment behavior, and payment fails
     */
    public function switchSubscription(SubscriptionPlan $plan, bool $requirePaymentCard = true): SpikeSubscription
    {
        $existingSubscription = $this->getSubscription();

        if ($requirePaymentCard && $existingSubscription->hasPaymentCard()) {
            $options = $this->buildSubscriptionUpdateOptions($existingSubscription);

            $mandateData = Spike::mandateDataFromRequest();

            $subscription = $this->getSubscription()
                ->withPaymentConfirmationOptions($mandateData ? [
                    'mandate_data' => $mandateData,
                ] : [])
                ->swapAndInvoice($plan->payment_provider_price_id, $options);

            if (array_key_exists('promotion_code', $options)) {
                $subscription->forceFill(['promotion_code_id' => $options['promotion_code']])->save();
            }
        }

        if ($requirePaymentCard && ! $existingSubscription->hasPaymentCard()) {
            $subscription = $this->createSubscription($plan, $requirePaymentCard);
            $existingSubscription->delete();

            return $subscription;
        }

        // without payment card, we need to manually update the subscription
        $subscription = $this->getSubscription();
        $subscription->stripe_price = $plan->payment_provider_price_id;
        $subscription->created_at = now();
        $subscription->renews_at = $plan->isYearly() ? now()->addYear() : now()->addMonthNoOverflow();
        $subscription->save();

        $subscription->items()->update([
            'stripe_price' => $plan->payment_provider_price_id,
        ]);

        return $subscription;
    }

    public function cancelSubscription(): ?Subscription
    {
        return optional($this->getSubscription())->cancel();
    }

    public function cancelSubscriptionNow(): ?Subscription
    {
        return optional($this->getSubscription())->cancelNowAndInvoice();
    }

    public function resumeSubscription(): ?Subscription
    {
        return optional($this->getSubscription())->stopCancelation();
    }

    /**
     * Build subscription update options for Stripe API
     *
     * @param \Opcodes\Spike\Contracts\SpikeSubscription $existingSubscription Current subscription
     * @return array Options for subscription update
     */
    public function buildSubscriptionUpdateOptions($existingSubscription): array
    {
        $options = [];
        $promotionCode = $this->getBillable()->stripePromotionCode();
        $existingPromotionCodeId = $existingSubscription->getPromotionCodeId();

        // Handle promotion codes
        if ($promotionCode && $promotionCode->id !== $existingPromotionCodeId) {
            $options = ['promotion_code' => $promotionCode->id];
        } elseif (!$promotionCode && $existingPromotionCodeId && ! $this->stripePersistDiscountsWhenSwitchingPlans()) {
            $options = ['promotion_code' => null];
        }

        // Add error_if_incomplete to prevent subscription update when payment fails, unless configured otherwise
        if (! $this->stripeAllowIncompleteSubscriptionUpdates()) {
            $options['payment_behavior'] = 'error_if_incomplete';
        }

        return $options;
    }

    public function findStripePromotionCode(string $promo_code_id = null): ?PromotionCode
    {
        if (is_null($promo_code_id)) {
            return null;
        }

        try {
            $stripePromotionCode = $this->stripe()->promotionCodes->retrieve($promo_code_id, [
                'expand' => ['coupon.applies_to', 'coupon.currency_options'],
            ]);
        } catch (InvalidRequestException $exception) {
            if ($exception->getHttpStatus() !== 404) {
                Log::warning('Failed to retrieve Stripe promotion code.', [
                    'promo_code_id' => $promo_code_id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if (! isset($stripePromotionCode)) {
            try {
                $codes = $this->stripe()->promotionCodes->all([
                    'code' => $promo_code_id,
                    'expand' => ['data.coupon.applies_to', 'data.coupon.currency_options'],
                ]);

                $stripePromotionCode = $codes->first();
            } catch (InvalidRequestException $exception) {
                Log::warning('Failed to retrieve Stripe promotion code.', [
                    'promo_code_id' => $promo_code_id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return isset($stripePromotionCode) ? new PromotionCode($stripePromotionCode) : null;
    }

    public function findStripeCouponCode(string $coupon_code_id = null): ?Coupon
    {
        if (is_null($coupon_code_id)) {
            return null;
        }

        try {
            $stripeCoupon = $this->stripe()->coupons->retrieve($coupon_code_id, [
                'expand' => ['applies_to', 'currency_options'],
            ]);
        } catch (InvalidRequestException $exception) {
            Log::warning('Failed to retrieve Stripe coupon code.', [
                'coupon_code_id' => $coupon_code_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return $stripeCoupon ? new Coupon($stripeCoupon) : null;
    }

    public function applyPromotionCode(string $promo_code_id): void
    {
        $this->getSubscription()?->applyPromotionCode($promo_code_id);
    }

    public function applyCoupon(string $coupon_id): void
    {
        $this->getSubscription()?->applyCoupon($coupon_id);
    }

    public function isCouponValidForPrice(Coupon $coupon, string $priceId = null): bool
    {
        $stripePrice = $this->stripe()->prices->retrieve($priceId);

        if (! $stripePrice) {
            return false;
        }

        $appliesToProducts = $coupon->asStripeCoupon()->applies_to?->products;

        if (is_array($appliesToProducts) && ! in_array($stripePrice->product, $appliesToProducts)) {
            return false;
        }

        return true;
    }

    public function hasIncompleteSubscriptionPayment(): bool
    {
        return $this->getSubscription()?->hasIncompletePayment() ?? false;
    }

    public function latestSubscriptionPayment(): \Laravel\Paddle\Payment|\Laravel\Cashier\Payment|null
    {
        return $this->getSubscription()?->latestPayment();
    }

    /**
     * Check if the payment gateway should persist discounts when switching plans
     *
     * @return bool
     */
    public function stripePersistDiscountsWhenSwitchingPlans(): bool
    {
        return Spike::stripePersistDiscountsWhenSwitchingPlans();
    }

    /**
     * Check if the payment gateway should allow incomplete subscription updates
     *
     * @return bool
     */
    public function stripeAllowIncompleteSubscriptionUpdates(): bool
    {
        return Spike::stripeAllowIncompleteSubscriptionUpdates();
    }

    /**
     * Get the Stripe instance.
     *
     * @return \Stripe\StripeClient
     */
    protected function stripe()
    {
        return Cashier::stripe();
    }

    // =========================================================================
    // License Quantity Management
    // =========================================================================

    /**
     * Add license quantity to the subscription.
     * Creates or increments a subscription item for the license type.
     *
     * @param string $priceId The Stripe price ID for the license type
     * @param int $quantity Number of licenses to add
     * @return bool Whether the operation was successful
     */
    public function addLicenseQuantity(string $priceId, int $quantity = 1): bool
    {
        $subscription = $this->getSubscription();

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        $existingItem = $subscription->items->first(function ($item) use ($priceId) {
            return $item->stripe_price === $priceId;
        });

        if ($existingItem) {
            // Increment existing item quantity
            $newQuantity = $existingItem->quantity + $quantity;
            $existingItem->updateQuantity($newQuantity);
        } else {
            // Add new subscription item
            $subscription->addPrice($priceId, $quantity);
        }

        return true;
    }

    /**
     * Remove license quantity from the subscription.
     * Decrements or removes a subscription item for the license type.
     *
     * @param string $priceId The Stripe price ID for the license type
     * @param int $quantity Number of licenses to remove
     * @return bool Whether the operation was successful
     */
    public function removeLicenseQuantity(string $priceId, int $quantity = 1): bool
    {
        $subscription = $this->getSubscription();

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        $existingItem = $subscription->items->first(function ($item) use ($priceId) {
            return $item->stripe_price === $priceId;
        });

        if (! $existingItem) {
            return false;
        }

        $newQuantity = max(0, $existingItem->quantity - $quantity);

        if ($newQuantity === 0) {
            // Remove the subscription item entirely
            $subscription->removePrice($priceId);
        } else {
            // Decrement the quantity
            $existingItem->updateQuantity($newQuantity);
        }

        return true;
    }

    /**
     * Set the exact license quantity on the subscription.
     *
     * @param string $priceId The Stripe price ID for the license type
     * @param int $quantity Exact number of licenses to set
     * @return bool Whether the operation was successful
     */
    public function setLicenseQuantity(string $priceId, int $quantity): bool
    {
        $subscription = $this->getSubscription();

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        $existingItem = $subscription->items->first(function ($item) use ($priceId) {
            return $item->stripe_price === $priceId;
        });

        if ($quantity === 0) {
            // Remove the subscription item if it exists
            if ($existingItem) {
                $subscription->removePrice($priceId);
            }

            return true;
        }

        if ($existingItem) {
            // Update existing item quantity
            $existingItem->updateQuantity($quantity);
        } else {
            // Add new subscription item
            $subscription->addPrice($priceId, $quantity);
        }

        return true;
    }

    /**
     * Get the current license quantity for a price ID.
     *
     * @param string $priceId The Stripe price ID for the license type
     * @return int Current quantity (0 if not found)
     */
    public function getLicenseQuantity(string $priceId): int
    {
        $subscription = $this->getSubscription();

        if (! $subscription || ! $subscription->valid()) {
            return 0;
        }

        $existingItem = $subscription->items->first(function ($item) use ($priceId) {
            return $item->stripe_price === $priceId;
        });

        return $existingItem?->quantity ?? 0;
    }
}
