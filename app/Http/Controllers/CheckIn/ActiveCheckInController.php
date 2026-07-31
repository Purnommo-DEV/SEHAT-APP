<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class ActiveCheckInController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Belum ada event aktif. Aktifkan event sebelum membuka meja check-in.');
        }

        return redirect()->route('events.check-ins.index', $event);
    }
}
