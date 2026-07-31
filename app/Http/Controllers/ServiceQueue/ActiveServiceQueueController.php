<?php

namespace App\Http\Controllers\ServiceQueue;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Workflow\ServicePostAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ActiveServiceQueueController extends Controller
{
    public function __invoke(ServicePostAccessService $accessService): RedirectResponse|View
    {
        $event = Event::query()->active()->first();

        if ($event === null) {
            return view('service-queues.select', ['event' => null, 'servicePosts' => collect()]);
        }

        $servicePosts = $accessService->postsForUser($event, request()->user());

        if ($servicePosts->count() === 1) {
            return redirect()->route('events.service-queues.index', [$event, $servicePosts->first()]);
        }

        return view('service-queues.select', compact('event', 'servicePosts'));
    }
}
