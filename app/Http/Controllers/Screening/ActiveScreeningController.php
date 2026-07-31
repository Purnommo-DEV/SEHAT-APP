<?php

namespace App\Http\Controllers\Screening;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ActiveScreeningController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('queues.active');
    }
}
