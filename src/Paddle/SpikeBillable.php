<?php

namespace Opcodes\Spike\Paddle;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Paddle\Billable;
use Opcodes\Spike\SpikeInvoice;
use Opcodes\Spike\Traits\ManagesCredits;
use Opcodes\Spike\Traits\ManagesLicenses;
use Opcodes\Spike\Traits\ManagesPromotionCode;
use Opcodes\Spike\Traits\ManagesPurchases;
use Opcodes\Spike\Traits\ManagesSubscriptions;

/**
 * @mixin Billable|Model
 *
 * @property-read Collection|Subscription[] $subscriptions
 *
 * @method Subscription subscription(string $name = 'default')
 */
trait SpikeBillable
{
    use Billable;
    use ManagesCredits;
    use ManagesLicenses;
    use ManagesPurchases;
    use ManagesSubscriptions;
    use ManagesPromotionCode;

    public function spikeCacheIdentifier(): string
    {
        return $this->getMorphClass() . ':' . $this->getKey();
    }

    public function spikeEmail()
    {
        return $this->paddleEmail();
    }

    public function spikeInvoices()
    {
        return $this->transactions()
            ->orderBy('id', 'desc')
            ->whereNotNull('invoice_number')
            ->get()
            ->map(fn(Transaction $transaction) => new SpikeInvoice(
                id: $transaction->id,
                number: $transaction->invoice_number,
                date: $transaction->billed_at,
                status: $transaction->status,
                total: $transaction->total()
            ));
    }
}
