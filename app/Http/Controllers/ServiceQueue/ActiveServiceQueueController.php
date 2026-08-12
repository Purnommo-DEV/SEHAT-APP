<?php

namespace App\Http\Controllers\ServiceQueue;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class ActiveServiceQueueController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $event = Event::query()->active()->first();

        if ($event === null) {
            return redirect()->route('dashboard')->with('status', 'Belum ada event aktif untuk workflow operasional.');
        }

        return redirect()->route('events.operations.waiting.desk', $event);
    }
}
