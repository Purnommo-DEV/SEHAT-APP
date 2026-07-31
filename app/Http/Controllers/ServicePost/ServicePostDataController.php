<?php

namespace App\Http\Controllers\ServicePost;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServicePostResource;
use App\Models\Event;
use App\Models\ServicePost;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServicePostDataController extends Controller
{
    public function __invoke(Event $event): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [ServicePost::class, $event]);

        return ServicePostResource::collection($event->servicePosts()->get());
    }
}
