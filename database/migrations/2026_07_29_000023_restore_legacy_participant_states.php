<?php

use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $participants = DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::WaitingService->value,
                ParticipantStatus::ServiceInProgress->value,
            ])
            ->whereNotNull('current_service_post_id')
            ->select(['id', 'current_service_post_id'])
            ->orderBy('id')
            ->get();

        foreach ($participants as $participant) {
            $legacyType = ServicePostType::tryFrom((string) DB::table('service_posts')
                ->where('id', $participant->current_service_post_id)
                ->value('type'));

            if ($legacyType === null || $legacyType === ServicePostType::Custom) {
                continue;
            }

            $ticketStatus = QueueTicketStatus::tryFrom((string) DB::table('queue_tickets')
                ->where('event_participant_id', $participant->id)
                ->where('service_post_id', $participant->current_service_post_id)
                ->value('status'));
            $inProgress = $ticketStatus === QueueTicketStatus::Serving;
            $status = match ($legacyType) {
                ServicePostType::Health => $inProgress
                    ? ParticipantStatus::HealthInProgress
                    : ParticipantStatus::WaitingHealth,
                ServicePostType::Screening => ParticipantStatus::WaitingScreening,
                ServicePostType::Donation => $inProgress
                    ? ParticipantStatus::DonationInProgress
                    : ParticipantStatus::WaitingDonor,
                ServicePostType::Completion => ParticipantStatus::DonationCompleted,
                ServicePostType::Registration => null,
            };

            if ($status !== null) {
                DB::table('event_participants')
                    ->where('id', $participant->id)
                    ->update(['status' => $status->value]);
            }
        }
    }

    public function down(): void
    {
        DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::WaitingHealth->value,
                ParticipantStatus::WaitingScreening->value,
                ParticipantStatus::WaitingDonor->value,
                ParticipantStatus::DonationCompleted->value,
            ])
            ->update(['status' => ParticipantStatus::WaitingService->value]);

        DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::HealthInProgress->value,
                ParticipantStatus::DonationInProgress->value,
            ])
            ->update(['status' => ParticipantStatus::ServiceInProgress->value]);
    }
};
