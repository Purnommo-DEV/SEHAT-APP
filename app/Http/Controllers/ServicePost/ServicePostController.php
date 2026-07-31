<?php

namespace App\Http\Controllers\ServicePost;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServicePost\StoreServicePostRequest;
use App\Http\Requests\ServicePost\UpdateServicePostRequest;
use App\Http\Resources\ServicePostResource;
use App\Models\Event;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\ServicePost\ServicePostService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServicePostController extends Controller
{
    public function index(Event $event, ServicePostService $servicePostService): View
    {
        $this->authorize('viewAny', [ServicePost::class, $event]);

        $servicePosts = $this->servicePostsForIndex($event);

        $canBootstrapWorkflow = $servicePostService->canBootstrapWorkflow($event);

        return view('service-posts.index', [
            'event' => $event,
            'servicePosts' => $servicePosts,
            'servicePostsJson' => ServicePostResource::collection($servicePosts)->resolve(),
            'canCreateServicePost' => $servicePostService->canCreate($event),
            'canBootstrapWorkflow' => $canBootstrapWorkflow,
        ]);
    }

    public function create(Event $event, ServicePostService $servicePostService): View
    {
        $this->authorize('create', [ServicePost::class, $event]);

        abort_unless($servicePostService->canCreate($event), 403);

        return view('service-posts.create', [
            'event' => $event,
            'activeRepair' => $event->status->value === 'active',
            'creatableBehaviors' => $servicePostService->creatableBehaviors($event),
            'operators' => $this->operators(),
        ]);
    }

    public function store(
        StoreServicePostRequest $request,
        Event $event,
        ServicePostService $servicePostService,
    ): RedirectResponse {
        $servicePostService->create($event, $request->validated(), $request->user());

        return redirect()
            ->to(route('events.show', $event).'#jalur-pelayanan')
            ->with('status', 'Pos pelayanan berhasil ditambahkan.');
    }

    public function edit(Event $event, ServicePost $servicePost): View
    {
        $this->authorize('update', $servicePost);

        $servicePost->load('operators:id,name,email');

        return view('service-posts.edit', [
            'event' => $event,
            'servicePost' => $servicePost,
            'operators' => $this->operators(),
        ]);
    }

    public function update(
        UpdateServicePostRequest $request,
        Event $event,
        ServicePost $servicePost,
        ServicePostService $servicePostService,
    ): RedirectResponse {
        $servicePostService->update($event, $servicePost, $request->validated(), $request->user());

        return redirect()
            ->to(route('events.show', $event).'#jalur-pelayanan')
            ->with('status', 'Data pos pelayanan berhasil diperbarui.');
    }

    public function destroy(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        ServicePostService $servicePostService,
    ): RedirectResponse {
        $this->authorize('delete', $servicePost);

        $servicePostService->delete($event, $servicePost, $request->user());

        return redirect()
            ->to(route('events.show', $event).'#jalur-pelayanan')
            ->with('status', 'Pos pelayanan berhasil dihapus.');
    }

    /**
     * @return Collection<int, ServicePost>
     */
    private function servicePostsForIndex(Event $event): Collection
    {
        return $event->servicePosts()->with('operators:id,name')->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function operators(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->permission(PermissionName::ManageOwnQueue->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
