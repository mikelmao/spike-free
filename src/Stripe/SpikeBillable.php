<?php

namespace Opcodes\Spike\Stripe;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Invoice;
use Opcodes\Spike\SpikeInvoice;
use Opcodes\Spike\Traits\ManagesCredits;
use Opcodes\Spike\Traits\ManagesLicenses;
use Opcodes\Spike\Traits\ManagesPromotionCode;
use Opcodes\Spike\Traits\ManagesPurchases;
use Opcodes\Spike\Traits\ManagesSubscriptions;

/**
 * @mixin Billable
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
        return $this->stripeEmail();
    }

    public function spikeInvoices()
    {
        return $this->invoices()->map(fn(Invoice $invoice) => new SpikeInvoice(
            id: $invoice->id,
            number: $invoice->number,
            date: $invoice->date(),
            status: $invoice->status,
            total: $invoice->total()
        ));
    }
}
