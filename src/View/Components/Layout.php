<?php

namespace Opcodes\Spike\View\Components;

use Opcodes\Spike\Facades\Credits;
use Opcodes\Spike\Facades\PaymentGateway;
use Opcodes\Spike\Facades\Spike;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Layout extends Component
{
    public function render(): View
    {
        $billable = Spike::resolve();

        if (config('spike.theme.display_avatar', true)) {
            $avatarUrl = Spike::resolveAvatar();
        }

        $themeColor = config('spike.theme.color');
        if (empty($themeColor)) {
            $themeColor = '#047857';
        }

        return view('spike::components.layout', [
            'billable' => $billable,
            'displayAvatar' => config('spike.theme.display_avatar', true),
            'avatarUrl' => $avatarUrl ?? null,
            'creditBalance' => Credits::billable($billable)->balance(),
            'themeColor' => $themeColor,
            'navLinks' => $this->getNavigationLinks(),
            'hasIncompleteSubscriptionPayment' => PaymentGateway::hasIncompleteSubscriptionPayment(),
        ]);
    }

    protected function getNavigationLinks()
    {
        $subscription = Spike::resolve()->getSubscription();

        return array_filter([
            [
                'label' => __('spike::translations.usage'),
                'route_name' => 'spike.usage',
                'icon' => 'spike::icons.data-usage',
            ],
            Spike::productsAvailable() ? [
                'label' => __('spike::translations.buy'),
                'route_name' => 'spike.purchase',
                'icon' => 'spike::icons.money'
            ] : null,
            Spike::subscriptionPlansAvailable() ? [
                'label' => __('spike::translations.subscribe'),
                'route_name' => 'spike.subscribe',
                'icon' => 'spike::icons.arrow-repeat-all',
                'needs_attention' => ($subscription?->isPastDue() ?? false)
                    && request()->query('state') !== 'payment-method-updated',
            ] : null,
            $this->licensesAvailable() ? [
                'label' => __('spike::translations.licenses'),
                'route_name' => 'spike.licenses',
                'icon' => 'spike::icons.shield-checkmark',
            ] : null,
            [
                'label' => __('spike::translations.billing'),
                'route_name' => 'spike.invoices',
                'icon' => 'spike::icons.wallet-credit-card'
            ],
        ]);
    }

    protected function licensesAvailable(): bool
    {
        $licenseTypes = config('spike.license_types', []);

        return ! empty($licenseTypes);
    }
}
