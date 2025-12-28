<?php

namespace Opcodes\Spike\Http\Livewire;

use LivewireUI\Modal\ModalComponent;
use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\PaymentGateway;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\LicenseType;

class ManageLicenseModal extends ModalComponent
{
    public string $typeId;

    public int $quantity = 1;

    public int $currentPurchased = 0;

    public ?string $errorMessage = null;

    protected $listeners = [
        'paymentMethodAdded' => '$refresh',
    ];

    public function mount(string $typeId): void
    {
        $this->typeId = $typeId;
        $billable = Spike::resolve();
        $pool = Licenses::billable($billable)->type($typeId)->pool();
        $this->currentPurchased = $pool->purchased;
        $this->quantity = max(1, $this->currentPurchased);
    }

    public function render()
    {
        $billable = Spike::resolve();
        $type = LicenseType::find($this->typeId);
        $pool = Licenses::billable($billable)->type($this->typeId)->pool();

        $priceInCents = $type->priceInCents();
        $quantityChange = $this->quantity - $this->currentPurchased;
        $monthlyChange = $quantityChange * $priceInCents;

        // Calculate if removing would cause over-allocation
        $canDecrease = true;
        $decreaseWarning = null;

        if ($this->quantity < $this->currentPurchased) {
            $quantityToRemove = $this->currentPurchased - $this->quantity;
            $manager = Licenses::billable($billable)->type($this->typeId);
            $canRemoveResult = $manager->canRemovePurchased($quantityToRemove);

            if ($canRemoveResult !== true) {
                $canDecrease = false;
                $decreaseWarning = $canRemoveResult['message'];
            }
        }

        return view('spike::livewire.manage-license-modal', [
            'type' => $type,
            'pool' => $pool,
            'billable' => $billable,
            'hasPaymentMethod' => $billable->hasDefaultPaymentMethod(),
            'priceInCents' => $priceInCents,
            'quantityChange' => $quantityChange,
            'monthlyChange' => $monthlyChange,
            'canDecrease' => $canDecrease,
            'decreaseWarning' => $decreaseWarning,
            'isIncrease' => $quantityChange > 0,
            'isDecrease' => $quantityChange < 0,
            'hasChanges' => $quantityChange !== 0,
        ]);
    }

    public function increment(): void
    {
        $this->quantity++;
    }

    public function decrement(): void
    {
        if ($this->quantity > 0) {
            $this->quantity--;
        }
    }

    public function confirm(): mixed
    {
        $this->errorMessage = null;
        $billable = Spike::resolve();
        $type = LicenseType::find($this->typeId);

        if (! $type) {
            $this->errorMessage = __('spike::translations.license_type_not_found');

            return null;
        }

        $quantityChange = $this->quantity - $this->currentPurchased;

        if ($quantityChange === 0) {
            $this->closeModal();

            return null;
        }

        if (! $billable->hasDefaultPaymentMethod() && $quantityChange > 0) {
            $this->errorMessage = __('spike::translations.no_payment_method_for_licenses');

            return null;
        }

        // Validate removal before proceeding
        if ($quantityChange < 0) {
            $quantityToRemove = abs($quantityChange);
            $manager = Licenses::billable($billable)->type($this->typeId);
            $canRemoveResult = $manager->canRemovePurchased($quantityToRemove);

            if ($canRemoveResult !== true) {
                $this->errorMessage = $canRemoveResult['message'];

                return null;
            }
        }

        try {
            $priceId = $type->priceId();

            if ($quantityChange > 0) {
                // Adding licenses
                PaymentGateway::billable($billable)->addLicenseQuantity($priceId, $quantityChange);
            } else {
                // Removing licenses
                PaymentGateway::billable($billable)->removeLicenseQuantity($priceId, abs($quantityChange));
            }

            $this->dispatch('licensesUpdated');
            $this->closeModal();

            return null;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }
    }

    public static function closeModalOnClickAway(): bool
    {
        return false;
    }

    public static function closeModalOnEscape(): bool
    {
        return true;
    }

    public static function modalMaxWidth(): string
    {
        return 'md';
    }
}

