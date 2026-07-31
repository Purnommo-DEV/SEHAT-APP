<?php

namespace App\Http\Controllers\Donation;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ActiveDonationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('queues.active');
    }
}
