<?php

namespace App\Http\Middleware;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits unauthenticated operational URLs to their currently active event.
 *
 * Route binding normally scopes nested models. These checks preserve that
 * boundary even when a route is called directly or changed in the future.
 */
class EnsureActiveOperationalEvent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');

        if (! $event instanceof Event && is_scalar($event)) {
            $event = Event::query()->find($event);
            $request->route()->setParameter('event', $event);
        }

        abort_unless(
            $event instanceof Event
                && $event->status === EventStatus::Active
                && $event->active_marker === 'active',
            404,
        );

        foreach (['eventParticipant', 'servicePost', 'queueTicket'] as $parameter) {
            $resource = $request->route($parameter);

            if ($resource instanceof EventParticipant
                || $resource instanceof ServicePost
                || $resource instanceof QueueTicket) {
                abort_unless((int) $resource->event_id === (int) $event->id, 404);
            }
        }

        return $next($request);
    }
}
