<?php

namespace Opcodes\Spike;

use Carbon\CarbonInterface;
use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
use Opcodes\Spike\Actions\Subscriptions\ProvideSubscriptionPlanMonthlyProvides;
use Opcodes\Spike\Contracts\PaymentGatewayContract;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\Events\SubscriptionActivated;
use Opcodes\Spike\Events\SubscriptionCancelled;
use Opcodes\Spike\Events\SubscriptionDeactivated;
use Opcodes\Spike\Events\SubscriptionResumed;
use Opcodes\Spike\Exceptions\InvalidSubscriptionPlanException;
use Opcodes\Spike\Exceptions\OverAllocationException;
use Opcodes\Spike\Facades\Credits;
use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\PaymentGateway;
use Opcodes\Spike\Traits\ScopedToBillable;

class SubscriptionManager
{
    use ScopedToBillable;

    protected function paymentGateway(): PaymentGatewayContract
    {
        return PaymentGateway::billable($this->getBillable());
    }

    /**
     * @throws \Laravel\Cashier\Exceptions\SubscriptionUpdateFailure
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment|\Exception
     * @throws \Stripe\Exception\CardException
     * @throws OverAllocationException
     */
    public function subscribeTo(SubscriptionPlan $plan, bool $requirePaymentCard = true): ?SpikeSubscription
    {
        if (empty($plan->payment_provider_price_id)) {
            throw new InvalidSubscriptionPlanException;
        }

        $billable = $this->getBillable();
        $currentSubscriptionPlan = $billable->currentSubscriptionPlan();

        // Validate license allocation before switching plans
        $this->validateLicenseAllocationForPlanSwitch($plan);

        if ($this->isSubscribed() && $plan->isFree()) {
            return $this->cancelSubscription();
            // nothing to do here, because we're not issuing any refunds or price changes.
        }

        if ($this->isSubscribedTo($plan)) {
            // The user is resuming their cancellation
            return $this->resumeSubscription();
        }

        if ($plan->isPaid()) {
            // in this case, the price is different, and they're already subscribed to something else.
            // So let's switch and prorate.

            if ($this->isSubscribed()) {
                // Step 1 - switch the subscription plan
                $subscription = $this->paymentGateway()->switchSubscription($plan, $requirePaymentCard);
            } else {
                // Step 1 - create the new subscription
                $subscription = $this->paymentGateway()->createSubscription($plan, $requirePaymentCard);
            }

            $billable->load('subscriptions');

            // Step 2 - prorate and expire old credit transactions
            $previousSubscriptionCreditTransactions = CreditTransaction::query()
                ->whereBillable($billable)
                ->notExpired()
                ->onlySubscriptions()
                ->get();

            foreach ($previousSubscriptionCreditTransactions as $transaction) {
                // To avoid abuse, we have to pro-rate these existing transactions before we expire them.
                // This way, any over-used credits will be taken from the new quota.
                $transaction->prorateTo(now());
                $transaction->expire();
                Credits::billable($billable)->clearCache();
            }

            // Step 3 - provide the benefits
            foreach ($subscription->items as $item) {
                app(ProvideSubscriptionPlanMonthlyProvides::class)
                    ->handle($plan, $billable, $item);
            }

            event(new SubscriptionDeactivated($billable, $currentSubscriptionPlan));
            event(new SubscriptionActivated($billable, $plan));

            return $subscription;
        }

        return null;
    }

    /**
     * @throws SubscriptionUpdateFailure
     */
    public function subscribeWithoutPaymentTo(SubscriptionPlan $plan): ?SpikeSubscription
    {
        return $this->subscribeTo($plan, false);
    }

    public function getSubscription(): ?SpikeSubscription
    {
        return $this->paymentGateway()->getSubscription();
    }

    public function getRenewalDate(): ?CarbonInterface
    {
        return $this->paymentGateway()->getRenewalDate();
    }

    public function getMonthlyRenewalDate(): ?CarbonInterface
    {
        $originalRenewalDate = $this->getRenewalDate();

        if (isset($originalRenewalDate)) {
            $renewalDate = $originalRenewalDate->copy();
        } elseif (is_null($originalRenewalDate) && $this->billable->subscriptions()->exists()) {
            $renewalDate = $this->billable->subscriptions()->latest()->first()->ends_at?->copy()->addMonthNoOverflow();
        }

        // in case we couldn't find the renewal date on either current or previous subscription,
        // we'll just use the user's created_at date.
        if (! isset($renewalDate)) {
            $billableCreatedAt = $this->billable->{$this->billable::CREATED_AT}->copy();
            $renewalDate = $billableCreatedAt->copy();
            $addMonths = 0;

            do {
                $renewalDate = $billableCreatedAt->copy()->addMonthsNoOverflow(++$addMonths);
            } while ($renewalDate->copy()->endOfDay()->isPast());
        }

        // if the date is in the past, we should get the closest current month instead.
        while ($renewalDate->copy()->endOfDay()->isPast()) {
            $renewalDate = $renewalDate->addMonthNoOverflow();
        }

        // if the date is far into the future (because of yearly plan, etc),
        // we should get the closest current month instead.
        if ($originalRenewalDate) {
            do {
                $potentialRenewalDate = Utils::subMonthRelatedToOriginalDate($renewalDate, $originalRenewalDate);

                if ($potentialRenewalDate->isAfter(now()->startOfDay())) {
                    $renewalDate = $potentialRenewalDate->copy();
                } else {
                    break;
                }
            } while ($renewalDate->isAfter(now()->endOfDay()));
        }

        return $renewalDate;
    }

    public function isSubscribed(?SubscriptionPlan $plan = null): bool
    {
        return $this->paymentGateway()->subscribed($plan);
    }

    public function isSubscribedTo(SubscriptionPlan $plan): bool
    {
        return $this->isSubscribed($plan);
    }

    public function cancelSubscription(): ?SpikeSubscription
    {
        $currentSubscriptionPlan = $this->getBillable()->currentSubscriptionPlan();
        $subscription = $this->paymentGateway()->cancelSubscription();
        event(new SubscriptionCancelled($this->getBillable(), $currentSubscriptionPlan));

        return $subscription;
    }

    public function cancelSubscriptionNow(): ?SpikeSubscription
    {
        $billable = $this->getBillable();
        $currentSubscriptionPlan = $billable->currentSubscriptionPlan();
        $subscription = $this->paymentGateway()->cancelSubscriptionNow();

        $previousSubscriptionCreditTransactions = CreditTransaction::query()
            ->whereBillable($billable)
            ->notExpired()
            ->onlySubscriptions()
            ->get();

        foreach ($previousSubscriptionCreditTransactions as $transaction) {
            // To avoid abuse, we have to pro-rate these existing transactions before we expire them.
            // This way, any over-used credits will be taken from the new quota.
            $transaction->prorateTo(now());
            $transaction->expire();
            Credits::billable($billable)->clearCache();
        }

        event(new SubscriptionDeactivated($billable, $currentSubscriptionPlan));

        return $subscription;
    }

    public function resumeSubscription(): ?SpikeSubscription
    {
        $billable = $this->getBillable();
        $subscription = $this->paymentGateway()->resumeSubscription();
        event(new SubscriptionResumed($billable, $billable->currentSubscriptionPlan()));

        return $subscription;
    }

    /**
     * Check if the billable can switch to a new subscription plan.
     * Returns validation result with any blocking issues.
     *
     * @param SubscriptionPlan $plan The plan to switch to
     * @return array{can_switch: bool, issues: array}
     */
    public function canSwitchToPlan(SubscriptionPlan $plan): array
    {
        $newPlanLicenses = LicenseManager::extractLicensesFromPlan($plan);

        return Licenses::billable($this->getBillable())->canSwitchToPlan($newPlanLicenses);
    }

    /**
     * Validate that the billable can switch to the new plan without over-allocation.
     *
     * @param SubscriptionPlan $plan The plan to switch to
     * @throws OverAllocationException If switching would cause over-allocation
     */
    protected function validateLicenseAllocationForPlanSwitch(SubscriptionPlan $plan): void
    {
        $validation = $this->canSwitchToPlan($plan);

        if (! $validation['can_switch']) {
            $firstIssue = $validation['issues'][0] ?? null;
            $message = $firstIssue['message'] ?? 'Cannot switch plan due to license over-allocation.';

            throw new OverAllocationException($message);
        }
    }
}
