<?php

namespace App\Services\Workflow;

use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use Illuminate\Validation\ValidationException;

class QueueTicketStateMachine
{
    public function transition(QueueTicket $queueTicket, QueueTicketStatus $nextStatus): void
    {
        $currentStatus = $queueTicket->status;

        if (! in_array($nextStatus, $this->allowedTransitions($currentStatus), true)) {
            throw ValidationException::withMessages([
                'queue_ticket' => "Transisi tiket dari {$currentStatus->label()} ke {$nextStatus->label()} tidak diperbolehkan.",
            ]);
        }

        $queueTicket->status = $nextStatus;
    }

    /**
     * @return list<QueueTicketStatus>
     */
    private function allowedTransitions(QueueTicketStatus $status): array
    {
        return match ($status) {
            QueueTicketStatus::Waiting => [
                QueueTicketStatus::Calling,
                QueueTicketStatus::Skipped,
                QueueTicketStatus::Cancelled,
            ],
            QueueTicketStatus::Calling => [
                QueueTicketStatus::Serving,
                QueueTicketStatus::Skipped,
                QueueTicketStatus::Cancelled,
            ],
            QueueTicketStatus::Serving => [QueueTicketStatus::Finished],
            QueueTicketStatus::Skipped => [QueueTicketStatus::Calling, QueueTicketStatus::Cancelled],
            QueueTicketStatus::Finished, QueueTicketStatus::Cancelled => [],
        };
    }
}
