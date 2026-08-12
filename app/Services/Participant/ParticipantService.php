<?php

namespace App\Services\Participant;

use App\Enums\AuditAction;
use App\Events\ParticipantUpdated;
use App\Models\Participant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class ParticipantService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor): Participant
    {
        /** @var Participant $participant */
        $participant = $this->database->transaction(function () use ($attributes, $actor): Participant {
            $participant = Participant::query()->create($attributes);
            $this->writeAudit($participant, $actor, AuditAction::ParticipantCreated);

            return $participant;
        });

        $this->broadcast($participant, AuditAction::ParticipantCreated);

        return $participant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Participant $participant, array $attributes, User $actor): Participant
    {
        /** @var Participant $updatedParticipant */
        $updatedParticipant = $this->database->transaction(function () use ($participant, $attributes, $actor): Participant {
            $lockedParticipant = Participant::query()
                ->whereKey($participant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $this->snapshot($lockedParticipant);
            $lockedParticipant->fill($attributes);
            $lockedParticipant->save();

            $this->writeAudit($lockedParticipant, $actor, AuditAction::ParticipantUpdated, $oldValues);

            return $lockedParticipant;
        });

        $this->broadcast($updatedParticipant, AuditAction::ParticipantUpdated);

        return $updatedParticipant;
    }

    public function delete(Participant $participant, User $actor): void
    {
        $participantId = $participant->getKey();

        $this->database->transaction(function () use ($participant, $actor): void {
            $lockedParticipant = Participant::query()
                ->whereKey($participant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedParticipant->eventParticipants()->exists()) {
                throw ValidationException::withMessages([
                    'participant' => 'Peserta yang sudah terhubung ke event tidak dapat dihapus. Data masih dapat diperbarui.',
                ]);
            }

            $this->writeAudit($lockedParticipant, $actor, AuditAction::ParticipantDeleted);
            $lockedParticipant->delete();
        });

        ParticipantUpdated::dispatch($participantId, AuditAction::ParticipantDeleted);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function writeAudit(
        Participant $participant,
        ?User $actor,
        AuditAction $action,
        ?array $oldValues = null,
    ): void {
        $this->auditLogger->record(
            actor: $actor,
            subject: $participant,
            action: $action,
            eventId: null,
            oldValues: $oldValues,
            newValues: $this->snapshot($participant),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Participant $participant): array
    {
        return [
            'nik' => $participant->nik,
            'name' => $participant->name,
            'phone' => $participant->phone,
            'gender' => $participant->gender->value,
            'birth_date' => $participant->birth_date?->toDateString(),
            'address' => $participant->address,
        ];
    }

    private function broadcast(Participant $participant, AuditAction $action): void
    {
        ParticipantUpdated::dispatch($participant->id, $action);
    }
}
