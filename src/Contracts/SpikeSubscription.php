<?php

namespace Opcodes\Spike\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 * @property Collection|SpikeSubscriptionItem[] $items
 */
interface SpikeSubscription
{
    /**
     * @return SpikeBillable
     */
    public function getBillable();

    public function getPriceId(): ?string;

    public function isPastDue(): bool;

    public function getPromotionCodeId(): ?string;

    public function hasPaymentCard(): bool;

    public function hasPromotionCode(): bool;

    public function promotionCode();

    public function renewalDate(): ?CarbonInterface;

    public function cancel(bool $cancelNow = false);

    /**
     * @deprecated Use stopCancelation() instead. Will be removed in the next major release.
     */
    public function resume($resumeAt = null);

    public function stopCancelation();

    public function active();

    public function hasPriceId(string $priceId): bool;
}
