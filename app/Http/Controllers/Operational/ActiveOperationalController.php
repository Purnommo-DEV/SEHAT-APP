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

        return redirect()->route($this->routeFor($stage), $event);
    }

    public function waitingDesk(): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null || $event->status !== EventStatus::Active) {
            return redirect()->route('dashboard')->with('status', 'Belum ada event aktif untuk workflow operasional.');
        }

        return redirect()->route('events.operations.waiting.desk', $event);
    }

    private function routeFor(?string $stage): string
    {
        return match ($stage) {
            'health-check' => 'events.operations.health-check',
            'before-donor' => 'events.operations.health-check',
            'donating' => 'events.operations.donating',
            'completed' => 'events.operations.completed',
            default => 'events.operations.waiting',
        };
    }
}
