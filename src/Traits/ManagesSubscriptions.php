<?php

namespace Opcodes\Spike\Traits;

use Carbon\CarbonInterface;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\SubscriptionManager;
use Opcodes\Spike\SubscriptionPlan;

trait ManagesSubscriptions
{
    public function subscriptionManager(): SubscriptionManager
    {
        return (new SubscriptionManager())->billable($this);
    }

    public function isSubscribed(?SubscriptionPlan $plan = null): bool
    {
        return $this->subscriptionManager()->isSubscribed($plan);
    }

    public function isSubscribedTo(SubscriptionPlan $plan): bool
    {
        return $this->subscriptionManager()->isSubscribedTo($plan);
    }

    public function subscribeTo(SubscriptionPlan $plan, bool $requirePaymentCard = true): ?SpikeSubscription
    {
        return $this->subscriptionManager()->subscribeTo($plan, $requirePaymentCard);
    }

    public function subscribeWithoutPaymentTo(SubscriptionPlan $plan): ?SpikeSubscription
    {
        return $this->subscriptionManager()->subscribeWithoutPaymentTo($plan);
    }

    public function cancelSubscription(): ?SpikeSubscription
    {
        return $this->subscriptionManager()->cancelSubscription();
    }

    public function cancelSubscriptionNow(): ?SpikeSubscription
    {
        return $this->subscriptionManager()->cancelSubscriptionNow();
    }

    public function resumeSubscription(): ?SpikeSubscription
    {
        return $this->subscriptionManager()->resumeSubscription();
    }

    public function getSubscription(): ?SpikeSubscription
    {
        return $this->subscriptionManager()->getSubscription();
    }

    public function currentSubscriptionPlan(): ?SubscriptionPlan
    {
        if (!$this->subscribed()) {
            $freeMonthlyPlans = Spike::monthlySubscriptionPlans($this, true)
                ->filter->isFree()->values();

            $freePlan = $freeMonthlyPlans->filter->isActive()->first();

            if (! $freePlan) {
                $freePlan = $freeMonthlyPlans->first();
            }

            return $freePlan ?: SubscriptionPlan::defaultFreePlan();
        }

        $priceId = $this->getSubscription()->getPriceId();

        // If no price ID found, return free plan as fallback
        if (! $priceId) {
            return SubscriptionPlan::defaultFreePlan();
        }

        return Spike::findSubscriptionPlan($priceId, $this);
    }

    public function subscriptionRenewalDate(): ?CarbonInterface
    {
        return $this->subscriptionManager()->getRenewalDate();
    }

    public function subscriptionMonthlyRenewalDate(): ?CarbonInterface
    {
        return $this->subscriptionManager()->getMonthlyRenewalDate();
    }
}
