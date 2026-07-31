<?php

namespace Tests\Unit;

use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Services\Workflow\ParticipantStateMachine;
use App\Services\Workflow\QueueTicketStateMachine;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkflowStateMachineTest extends TestCase
{
    public function test_participant_state_machine_allows_the_operational_path(): void
    {
        $participant = new EventParticipant(['status' => ParticipantStatus::Registered]);
        $stateMachine = new ParticipantStateMachine;

        foreach ([
            ParticipantStatus::CheckedIn,
            ParticipantStatus::WaitingHealth,
            ParticipantStatus::HealthInProgress,
            ParticipantStatus::WaitingScreening,
            ParticipantStatus::WaitingDonor,
            ParticipantStatus::DonationInProgress,
            ParticipantStatus::DonationCompleted,
            ParticipantStatus::Finished,
        ] as $status) {
            $stateMachine->transition($participant, $status);
            $this->assertSame($status, $participant->status);
        }
    }

    public function test_participant_terminal_state_rejects_further_transition(): void
    {
        $participant = new EventParticipant(['status' => ParticipantStatus::Finished]);

        $this->expectException(ValidationException::class);

        (new ParticipantStateMachine)->transition($participant, ParticipantStatus::WaitingHealth);
    }

    public function test_queue_state_machine_allows_recall_and_completion(): void
    {
        $ticket = new QueueTicket(['status' => QueueTicketStatus::Waiting]);
        $stateMachine = new QueueTicketStateMachine;

        foreach ([
            QueueTicketStatus::Skipped,
            QueueTicketStatus::Calling,
            QueueTicketStatus::Serving,
            QueueTicketStatus::Finished,
        ] as $status) {
            $stateMachine->transition($ticket, $status);
            $this->assertSame($status, $ticket->status);
        }
    }

    public function test_queue_cannot_finish_before_service_starts(): void
    {
        $ticket = new QueueTicket(['status' => QueueTicketStatus::Waiting]);

        $this->expectException(ValidationException::class);

        (new QueueTicketStateMachine)->transition($ticket, QueueTicketStatus::Finished);
    }
}
