<?php

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ParticipantAutocompleteRequest;
use App\Http\Resources\ParticipantResource;
use App\Models\Participant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParticipantAutocompleteController extends Controller
{
    public function __invoke(ParticipantAutocompleteRequest $request): AnonymousResourceCollection
    {
        $term = (string) $request->validated('q');
        $normalizedTerm = mb_strtolower($term);
        $participants = Participant::query()
            ->matching($term)
            ->orderByRaw(
                <<<'SQL'
                    CASE
                        WHEN LOWER(name) = ? THEN 0
                        WHEN LOWER(name) LIKE ? THEN 1
                        WHEN phone LIKE ? THEN 2
                        ELSE 3
                    END
                SQL,
                [$normalizedTerm, "{$normalizedTerm}%", "{$term}%"],
            )
            ->orderBy('name')
            ->limit(10)
            ->get();

        return ParticipantResource::collection($participants);
    }
}
