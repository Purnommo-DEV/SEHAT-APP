<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ActiveHealthController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('queues.active');
    }
}
