<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\ServicePost;
use App\Services\Event\EventService;
use App\Services\ServicePost\ServicePostService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Event::class);

        $events = $this->eventsForIndex();

        return view('events.index', [
            'events' => $events,
            'eventsJson' => EventResource::collection($events)->resolve(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Event::class);

        return view('events.create');
    }

    public function store(StoreEventRequest $request, EventService $eventService): RedirectResponse
    {
        $event = $eventService->create($request->validated(), $request->user());

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Event berhasil dibuat sebagai draf.');
    }

    public function show(
        Request $request,
        Event $event,
        ServicePostService $servicePostService,
        WorkflowDefinitionService $workflowDefinition,
    ): View {
        $this->authorize('view', $event);

        $event->load([
            'creator',
            'settings',
            'servicePosts.operators:id,name',
            'auditLogs' => fn ($query) => $query->with('user')->latest('created_at')->limit(10),
        ])->loadCount('servicePosts');

        $canManageServicePosts = $request->user()?->can('viewAny', [ServicePost::class, $event]) ?? false;

        return view('events.show', [
            'event' => $event,
            'canManageServicePosts' => $canManageServicePosts,
            'canCreateServicePost' => $canManageServicePosts && $servicePostService->canCreate($event),
            'canBootstrapWorkflow' => $canManageServicePosts && $servicePostService->canBootstrapWorkflow($event),
            'workflowValidation' => $workflowDefinition->validate($event),
        ]);
    }

    public function edit(Event $event): View
    {
        $this->authorize('update', $event);

        return view('events.edit', compact('event'));
    }

    public function update(UpdateEventRequest $request, Event $event, EventService $eventService): RedirectResponse
    {
        $eventService->update($event, $request->validated(), $request->user());

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Data event berhasil diperbarui.');
    }

    public function destroy(Request $request, Event $event, EventService $eventService): RedirectResponse
    {
        $this->authorize('delete', $event);

        $eventService->delete($event, $request->user());

        return redirect()
            ->route('events.index')
            ->with('status', 'Event draf berhasil dihapus.');
    }

    /**
     * @return Collection<int, Event>
     */
    private function eventsForIndex(): Collection
    {
        return Event::query()
            ->with('settings')
            ->orderByRaw("case when active_marker = 'active' then 0 else 1 end")
            ->orderByDesc('starts_at')
            ->get();
    }
}
