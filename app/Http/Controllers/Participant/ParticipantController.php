<?php

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ParticipantSearchRequest;
use App\Http\Requests\Participant\StoreParticipantRequest;
use App\Http\Requests\Participant\UpdateParticipantRequest;
use App\Http\Resources\ParticipantResource;
use App\Models\Participant;
use App\Services\Participant\ParticipantService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantController extends Controller
{
    public function index(ParticipantSearchRequest $request): View
    {
        $participants = $this->participantsForIndex($request->validated('q'));

        return view('participants.index', [
            'query' => $request->validated('q') ?? '',
            'participants' => $participants,
            'participantsJson' => ParticipantResource::collection($participants)->resolve(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Participant::class);

        return view('participants.create');
    }

    public function store(StoreParticipantRequest $request, ParticipantService $participantService): RedirectResponse
    {
        $participant = $participantService->create($request->validated(), $request->user());

        return redirect()
            ->route('participants.index')
            ->with('status', "Peserta {$participant->name} berhasil ditambahkan.");
    }

    public function edit(Participant $participant): View
    {
        $this->authorize('update', $participant);

        return view('participants.edit', compact('participant'));
    }

    public function update(
        UpdateParticipantRequest $request,
        Participant $participant,
        ParticipantService $participantService,
    ): RedirectResponse {
        $participantService->update($participant, $request->validated(), $request->user());

        return redirect()
            ->route('participants.index')
            ->with('status', 'Data peserta berhasil diperbarui.');
    }

    public function destroy(Request $request, Participant $participant, ParticipantService $participantService): RedirectResponse
    {
        $this->authorize('delete', $participant);

        $participantService->delete($participant, $request->user());

        return redirect()
            ->route('participants.index')
            ->with('status', 'Data peserta berhasil dihapus.');
    }

    /**
     * @return Collection<int, Participant>
     */
    private function participantsForIndex(?string $query): Collection
    {
        return Participant::query()
            ->matching($query)
            ->orderBy('name')
            ->limit(100)
            ->get();
    }
}
