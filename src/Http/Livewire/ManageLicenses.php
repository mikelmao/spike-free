<?php

namespace Opcodes\Spike\Http\Livewire;

use Livewire\Component;
use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\Spike;
use Opcodes\Spike\LicenseType;

class ManageLicenses extends Component
{
    protected $listeners = [
        'licensesUpdated' => '$refresh',
    ];

    public function render()
    {
        $billable = Spike::resolve();
        $licenseTypes = LicenseType::all();
        $pools = [];

        foreach ($licenseTypes as $type) {
            $pool = Licenses::billable($billable)->type($type->type)->pool();
            $pools[$type->type] = [
                'type' => $type,
                'pool' => $pool,
                'total' => $pool->total(),
                'available' => $pool->available(),
                'allocated' => $pool->allocated(),
                'included' => $pool->included,
                'purchased' => $pool->purchased,
                'bundled' => $pool->bundled,
            ];
        }

        return view('spike::livewire.manage-licenses', [
            'licenseTypes' => $licenseTypes,
            'pools' => $pools,
            'billable' => $billable,
            'hasPaymentMethod' => $billable->hasDefaultPaymentMethod(),
        ]);
    }
}

