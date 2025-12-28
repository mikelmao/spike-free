<?php

namespace Opcodes\Spike\Stripe\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Opcodes\Spike\Actions\Subscriptions\ProvideSubscriptionPlanMonthlyProvides;
use Opcodes\Spike\Contracts\SpikeBillable;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\CreditTransaction;
use Opcodes\Spike\Events\SubscriptionActivated;
use Opcodes\Spike\Events\SubscriptionCancelled;
use Opcodes\Spike\Facades\Credits;
use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\PaymentGateway;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\LicenseType;

class StripeWebhookListener
{
    /**
     * Handle received Stripe webhooks.
     */
    public function handle(WebhookHandled $event): void
    {
        switch ($event->payload['type']) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionCancelled($event);
                break;

            default:
                break;
        }
    }

    protected function handleSubscriptionUpdated(WebhookHandled $event): void
    {
        /** @var SpikeBillable|Model $billable */
        list($billable, $plan, $status, $subId) = $this->extractBillablePlanStatusSubId($event);

        /** @var SpikeSubscription $subscription */
        $subscription = $billable->subscription();

        if (! $subscription) {
            Log::warning('[Spike\StripeEventListener] Got a webhook for a billable that does not have a subscription. Might be a race-condition with Cashier.', [
                'billable' => $billable->getKey(),
                'webhook_subscription' => $subId,
                'webhook' => $event->payload,
            ]);
            // Laravel Cashier hasn't created the subscription yet.
            return;
        }

        if ($subscription->stripe_id !== $subId) {
            Log::warning('[Spike\StripeEventListener] Got a webhook for a subscription that does not belong to the billable.', [
                'billable' => $billable->getKey(),
                'billable_subscription' => $subscription->stripe_id,
                'webhook_subscription' => $subId,
                'webhook' => $event->payload,
            ]);

            return;
        }

        if ($status !== \Stripe\Subscription::STATUS_ACTIVE) {
            return;
        }

        $subscriptionPaymentMethod = $event->payload['data']['object']['default_payment_method'] ?? null;

        if (! $billable->hasDefaultPaymentMethod() && $subscriptionPaymentMethod) {
            // save the default payment method onto the customer in case it's not there.
            // this is needed for switching plans later.
            $billable->updateDefaultPaymentMethod($subscriptionPaymentMethod);
        }

        // Step 1 - prorate and expire credits from a previous subscription
        $previousSubscriptionCreditTransactions = CreditTransaction::query()
            ->whereBillable($billable)
            ->notExpired()
            ->onlySubscriptions()
            ->where('subscription_id', '!=', $subscription->id)
            ->get();

        foreach ($previousSubscriptionCreditTransactions as $transaction) {
            // To avoid abuse, we have to pro-rate these existing transactions before we expire them.
            // This way, any over-used credits will be taken from the new quota.
            $transaction->prorateTo(now());
            $transaction->expire();
            Credits::billable($billable)->clearCache();
        }

        // Step 2 - provide providables for the new subscription (only if we have a plan)
        if ($plan) {
            foreach ($subscription->items as $subscriptionItem) {
                app(ProvideSubscriptionPlanMonthlyProvides::class)
                    ->handle($plan, $billable, $subscriptionItem);
            }

            $billable->credits()->expireCurrentUsageTransactions();

            event(new SubscriptionActivated($billable, $plan));
        }

        // Step 3 - sync license quantities from subscription items (always run this)
        $this->syncLicenseQuantitiesFromSubscription($billable, $event->payload['data']['object']);
    }

    protected function handleSubscriptionCancelled(WebhookHandled $event): void
    {
        // Currently the webhook should be handled only when Stripe Checkout is enabled.
        // That's because it's already handled otherwise in the SubscriptionManager.
        if (! Spike::stripeCheckoutEnabled()) {
            return;
        }

        list($billable, $plan, $status, $subId) = $this->extractBillablePlanStatusSubId($event);

        if ($status !== \Stripe\Subscription::STATUS_CANCELED) {
            return;
        }

        event(new SubscriptionCancelled($billable, $plan));
    }

    protected function extractBillablePlanStatusSubId(WebhookHandled $event): array
    {
        $subId = $event->payload['data']['object']['id'];
        $customer = $event->payload['data']['object']['customer'];
        $status = $event->payload['data']['object']['status'];

        // Handle both single-plan subscriptions (plan at top level) and multi-item subscriptions
        // For multi-item subscriptions, we need to find the main subscription plan from the items
        $priceId = $event->payload['data']['object']['plan']['id'] ?? null;

        if (! $priceId) {
            // Multi-item subscription - find the main plan from items
            $items = $event->payload['data']['object']['items']['data'] ?? [];
            foreach ($items as $item) {
                $itemPriceId = $item['price']['id'] ?? $item['plan']['id'] ?? null;
                if ($itemPriceId) {
                    // Check if this is a subscription plan (not a license price)
                    $potentialPlan = Spike::findSubscriptionPlan($itemPriceId);
                    if ($potentialPlan) {
                        $priceId = $itemPriceId;
                        break;
                    }
                }
            }
        }

        $billable = PaymentGateway::findBillable($customer);
        $plan = $priceId ? Spike::findSubscriptionPlan($priceId, $billable) : null;

        return [$billable, $plan, $status, $subId];
    }

    /**
     * Sync license pool quantities from Stripe subscription items.
     * This ensures the local license pool reflects the quantities in Stripe.
     *
     * @param SpikeBillable|Model $billable
     * @param array $subscriptionData The Stripe subscription object from the webhook
     */
    protected function syncLicenseQuantitiesFromSubscription($billable, array $subscriptionData): void
    {
        $licenseTypes = LicenseType::all();

        if ($licenseTypes->isEmpty()) {
            return;
        }

        // Build a map of price_id => quantity from Stripe subscription items
        $stripeQuantities = [];
        $items = $subscriptionData['items']['data'] ?? [];

        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            $quantity = $item['quantity'] ?? 0;

            if ($priceId) {
                $stripeQuantities[$priceId] = $quantity;
            }
        }

        // Sync each license type's purchased quantity
        foreach ($licenseTypes as $licenseType) {
            $priceId = $licenseType->priceId();

            if (! $priceId) {
                continue;
            }

            $stripeQuantity = $stripeQuantities[$priceId] ?? 0;

            try {
                Licenses::billable($billable)
                    ->type($licenseType)
                    ->setPurchased($stripeQuantity);
            } catch (\Exception $e) {
                Log::warning('[Spike\StripeEventListener] Failed to sync license quantity.', [
                    'billable' => $billable->getKey(),
                    'license_type' => $licenseType->type,
                    'stripe_quantity' => $stripeQuantity,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
