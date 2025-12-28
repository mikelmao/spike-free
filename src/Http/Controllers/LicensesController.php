<?php

namespace Opcodes\Spike\Http\Controllers;

use Opcodes\Spike\Facades\Licenses;
use Opcodes\Spike\Facades\Spike;

class LicensesController
{
    public function index()
    {
        $licenseTypes = config('spike.license_types', []);

        if (empty($licenseTypes)) {
            return redirect(route('spike.usage'));
        }

        return view('spike::licenses');
    }
}

