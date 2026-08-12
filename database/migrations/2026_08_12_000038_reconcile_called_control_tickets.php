<?php

use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Earlier control-desk calls updated only the queue ticket. Reconcile
     * records that are still waiting so the participant and ticket present
     * one operational state to the new unified screen.
     */
    public function up(): void
    {
        DB::table('event_participants as event_participant')
            ->where('event_participant.status', ParticipantStatus::Waiting->value)
            ->whereExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('queue_tickets as queue_ticket')
                    ->whereColumn('queue_ticket.event_participant_id', 'event_participant.id')
                    ->where('queue_ticket.status', QueueTicketStatus::Calling->value);
            })
            ->update([
                'status' => ParticipantStatus::Calling->value,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('event_participants as event_participant')
            ->where('event_participant.status', ParticipantStatus::Calling->value)
            ->whereExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('queue_tickets as queue_ticket')
                    ->whereColumn('queue_ticket.event_participant_id', 'event_participant.id')
                    ->where('queue_ticket.status', QueueTicketStatus::Calling->value);
            })
            ->update([
                'status' => ParticipantStatus::Waiting->value,
                'updated_at' => now(),
            ]);
    }
};
