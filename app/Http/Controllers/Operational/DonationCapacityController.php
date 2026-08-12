<?php

namespace App\Http\Controllers\Operational;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operational\UpdateDonationCapacityRequest;
use App\Models\Event;
use App\Services\Operational\DonationCapacityService;
use Illuminate\Http\JsonResponse;

class DonationCapacityController extends Controller
{
    public function update(
        UpdateDonationCapacityRequest $request,
        Event $event,
        DonationCapacityService $donationCapacity,
    ): JsonResponse {
        $snapshot = $donationCapacity->updateCapacities(
            event: $event,
            maleCapacity: (int) $request->validated('donation_capacity_male'),
            femaleCapacity: (int) $request->validated('donation_capacity_female'),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Kapasitas donor per gender berhasil diperbarui.',
            'data' => $snapshot->toArray(),
        ]);
    }
}
