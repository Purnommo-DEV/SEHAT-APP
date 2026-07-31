<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIn\StoreQuickParticipantRequest;
use App\Http\Resources\ParticipantResource;
use App\Services\Participant\ParticipantService;
use Illuminate\Http\JsonResponse;

class QuickParticipantController extends Controller
{
    public function __invoke(
        StoreQuickParticipantRequest $request,
        ParticipantService $participantService,
    ): JsonResponse {
        $participant = $participantService->create(
            $request->validated(),
            $request->user(),
        );

        return response()->json([
            'message' => "Peserta {$participant->name} berhasil ditambahkan dan dipilih.",
            'data' => (new ParticipantResource($participant))->resolve($request),
        ], 201);
    }
}
