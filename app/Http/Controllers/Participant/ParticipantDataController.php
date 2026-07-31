<?php

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ParticipantSearchRequest;
use App\Http\Resources\ParticipantResource;
use App\Models\Participant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParticipantDataController extends Controller
{
    public function __invoke(ParticipantSearchRequest $request): AnonymousResourceCollection
    {
        $participants = Participant::query()
            ->matching($request->validated('q'))
            ->orderBy('name')
            ->limit(100)
            ->get();

        return ParticipantResource::collection($participants);
    }
}
