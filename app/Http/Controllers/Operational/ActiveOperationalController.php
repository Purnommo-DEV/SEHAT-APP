<?php

namespace App\Http\Controllers\Operational;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class ActiveOperationalController extends Controller
{
    public function __invoke(?string $stage = null): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null || $event->status !== EventStatus::Active) {
            return redirect()->route('dashboard')->with('status', 'Belum ada event aktif untuk workflow operasional.');
        }

        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function waitingDesk(): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null || $event->status !== EventStatus::Active) {
            return redirect()->route('dashboard')->with('status', 'Belum ada event aktif untuk workflow operasional.');
        }

        return redirect()->route('events.operations.waiting.desk', $event);
    }
}
