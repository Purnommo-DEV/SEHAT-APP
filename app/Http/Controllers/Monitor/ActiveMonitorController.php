<?php

namespace App\Http\Controllers\Monitor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class ActiveMonitorController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null) {
            return redirect()->route('dashboard')->with('status', 'Belum ada event aktif untuk ditampilkan di monitor.');
        }

        return redirect()->route('events.monitor.show', $event);
    }
}
