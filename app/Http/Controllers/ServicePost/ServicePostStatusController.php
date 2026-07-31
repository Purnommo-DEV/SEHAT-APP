<?php

namespace App\Http\Controllers\ServicePost;

use App\Enums\ServicePostMoveDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServicePost\MoveServicePostRequest;
use App\Models\Event;
use App\Models\ServicePost;
use App\Services\ServicePost\ServicePostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServicePostStatusController extends Controller
{
    public function move(
        MoveServicePostRequest $request,
        Event $event,
        ServicePost $servicePost,
        ServicePostService $servicePostService,
    ): RedirectResponse {
        $servicePostService->move(
            event: $event,
            servicePost: $servicePost,
            direction: ServicePostMoveDirection::from($request->string('direction')->toString()),
            actor: $request->user(),
        );

        return redirect()
            ->to(route('events.show', $event).'#jalur-pelayanan')
            ->with('status', 'Urutan pos pelayanan berhasil diperbarui.');
    }

    public function toggle(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        ServicePostService $servicePostService,
    ): RedirectResponse {
        $this->authorize('update', $servicePost);

        $servicePostService->toggle($event, $servicePost, $request->user());

        return redirect()
            ->to(route('events.show', $event).'#jalur-pelayanan')
            ->with('status', 'Status pos pelayanan berhasil diperbarui.');
    }
}
