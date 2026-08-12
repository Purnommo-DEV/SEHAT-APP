<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ScreeningResult;
use App\Events\DashboardUpdated;
use App\Events\DonorQueueUpdated;
use App\Events\EventLifecycleUpdated;
use App\Events\HealthQueueUpdated;
use App\Events\OperationalSnapshotEvent;
use App\Events\ParticipantCheckedIn;
use App\Events\ParticipantDonationCompleted;
use App\Events\ParticipantEligible;
use App\Events\ParticipantHealthCheckCompleted;
use App\Events\ParticipantIneligible;
use App\Events\ParticipantMovedToDonation;
use App\Events\ParticipantMovedToEligibility;
use App\Events\ParticipantMovedToHealthCheck;
use App\Events\ParticipantRegistered;
use App\Events\ParticipantUpdated;
use App\Events\ParticipantWorkflowEvent;
use App\Events\QueueUpdated;
use App\Events\ScreeningUpdated;
use App\Events\ServicePostUpdated;
use App\Events\ServiceQueueUpdated;
use App\Events\TVMonitorUpdated;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RealtimeBroadcastContractTest extends TestCase
{
    /**
     * @param  string|list<string>  $expectedChannels
     * @param  array<string, int|string|null>  $expectedPayload
     */
    #[DataProvider('broadcastEvents')]
    public function test_realtime_events_share_the_queued_after_commit_contract(
        ShouldBroadcast $event,
        string|array $expectedChannels,
        string $expectedName,
        array $expectedPayload,
    ): void {
        $this->assertTrue($event->afterCommit);
        $this->assertSame('broadcasts', $event->broadcastQueue());
        $this->assertSame((array) $expectedChannels, array_map(
            static fn ($channel): string => (string) $channel,
            $event->broadcastOn(),
        ));
        $this->assertSame($expectedName, $event->broadcastAs());
        $this->assertSame($expectedPayload, $event->broadcastWith());
    }

    public function test_broadcast_job_is_dispatched_to_the_dedicated_queue_after_commit(): void
    {
        Queue::fake();

        EventLifecycleUpdated::dispatch(41, AuditAction::EventUpdated);

        Queue::assertPushedOn(
            'broadcasts',
            BroadcastEvent::class,
            fn (BroadcastEvent $job): bool => $job->afterCommit === true
                && $job->event instanceof EventLifecycleUpdated
                && $job->event->eventId === 41,
        );
    }

    /**
     * @param  array<string, int|string|null>  $payload
     */
    #[DataProvider('finalMasterEvents')]
    public function test_each_final_master_event_dispatches_an_after_commit_broadcast_job(
        ShouldBroadcast $event,
        string $channel,
        string $name,
        array $payload,
    ): void {
        Queue::fake();

        if ($event instanceof ParticipantWorkflowEvent) {
            $event::dispatch(
                $event->eventId,
                $event->eventParticipantId,
                $event->queueTicketId,
                $event->nextServicePostId,
            );
        } elseif ($event instanceof OperationalSnapshotEvent) {
            $event::dispatch(
                $event->eventId,
                $event->reason,
                $event->eventParticipantId,
                $event->queueTicketId,
                $event->servicePostId,
            );
        } else {
            $this->fail('Final Master event must use a supported realtime event contract.');
        }

        Queue::assertPushedOn(
            'broadcasts',
            BroadcastEvent::class,
            fn (BroadcastEvent $job): bool => $job->afterCommit === true
                && $event::class === $job->event::class,
        );
    }

    /**
     * @return iterable<string, array{
     *     ShouldBroadcast,
     *     string|list<string>,
     *     string,
     *     array<string, int|string|list<string>|null>
     * }>
     */
    public static function broadcastEvents(): iterable
    {
        yield 'event lifecycle' => [
            new EventLifecycleUpdated(41, AuditAction::EventUpdated),
            ['operational', 'private-events', 'private-events.41'],
            'event.lifecycle.updated',
            ['event_id' => 41, 'action' => AuditAction::EventUpdated->value],
        ];

        yield 'participant master' => [
            new ParticipantUpdated(51, AuditAction::ParticipantUpdated),
            'private-participants',
            'participant.updated',
            ['participant_id' => 51, 'action' => AuditAction::ParticipantUpdated->value],
        ];

        yield 'participant check-in' => [
            new ParticipantCheckedIn(41, 52, 53, 'K001', 'R001', ['donor', 'health_check']),
            'events.41',
            'participant.checked-in',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'queue_number' => 'K001',
                'registration_number' => 'R001',
                'services' => ['donor', 'health_check'],
            ],
        ];

        yield 'service post' => [
            new ServicePostUpdated(41, 61, AuditAction::ServicePostUpdated),
            'private-events.41',
            'service-post.updated',
            [
                'event_id' => 41,
                'service_post_id' => 61,
                'action' => AuditAction::ServicePostUpdated->value,
            ],
        ];

        yield 'dynamic service queue' => [
            new ServiceQueueUpdated(41, 61, 62, AuditAction::ServicePostCompleted, 63),
            'events.41',
            'service.queue.updated',
            [
                'event_id' => 41,
                'service_post_id' => 61,
                'queue_ticket_id' => 62,
                'action' => AuditAction::ServicePostCompleted->value,
                'next_service_post_id' => 63,
            ],
        ];

        yield 'health queue' => [
            new HealthQueueUpdated(41, 71, AuditAction::HealthAssessmentUpdated),
            'private-events.41',
            'health.queue.updated',
            [
                'event_id' => 41,
                'queue_ticket_id' => 71,
                'action' => AuditAction::HealthAssessmentUpdated->value,
            ],
        ];

        yield 'screening' => [
            new ScreeningUpdated(41, 81, ScreeningResult::Eligible),
            'private-events.41',
            'screening.updated',
            [
                'event_id' => 41,
                'event_participant_id' => 81,
                'result' => ScreeningResult::Eligible->value,
            ],
        ];

        yield 'donor queue' => [
            new DonorQueueUpdated(41, 91, AuditAction::DonationStarted),
            'private-events.41',
            'donor.queue.updated',
            [
                'event_id' => 41,
                'queue_ticket_id' => 91,
                'action' => AuditAction::DonationStarted->value,
            ],
        ];

        yield from self::finalMasterEvents();
    }

    /**
     * @return iterable<string, array{ShouldBroadcast, string, string, array<string, int|string|null>}>
     */
    public static function finalMasterEvents(): iterable
    {
        yield 'participant registered' => [
            new ParticipantRegistered(41, 52, 53, 61),
            'events.41',
            'participant.registered',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'next_service_post_id' => 61,
            ],
        ];

        yield 'participant moved to eligibility' => [
            new ParticipantMovedToEligibility(41, 52, 53, 61),
            'events.41',
            'participant.moved-to-eligibility',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'next_service_post_id' => 61,
            ],
        ];

        yield 'participant eligible' => [
            new ParticipantEligible(41, 52, 53, 62),
            'events.41',
            'participant.eligible',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'next_service_post_id' => 62,
            ],
        ];

        yield 'participant ineligible' => [
            new ParticipantIneligible(41, 52, 53, null),
            'events.41',
            'participant.ineligible',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'next_service_post_id' => null,
            ],
        ];

        yield 'participant moved to donation' => [
            new ParticipantMovedToDonation(41, 52, 73, 62),
            'events.41',
            'participant.moved-to-donation',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 73,
                'next_service_post_id' => 62,
            ],
        ];

        yield 'participant donation completed' => [
            new ParticipantDonationCompleted(41, 52, 63, 64),
            'events.41',
            'participant.donation-completed',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 63,
                'next_service_post_id' => 64,
            ],
        ];

        yield 'participant moved to health check' => [
            new ParticipantMovedToHealthCheck(41, 52, 75, 64),
            'events.41',
            'participant.moved-to-health-check',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 75,
                'next_service_post_id' => 64,
            ],
        ];

        yield 'participant health check completed' => [
            new ParticipantHealthCheckCompleted(41, 52, 65, null),
            'events.41',
            'participant.health-check-completed',
            [
                'event_id' => 41,
                'event_participant_id' => 52,
                'queue_ticket_id' => 65,
                'next_service_post_id' => null,
            ],
        ];

        yield 'queue updated' => [
            new QueueUpdated(41, 'service_post_completed', 52, 53, 61),
            'events.41',
            'queue.updated',
            [
                'event_id' => 41,
                'reason' => 'service_post_completed',
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'service_post_id' => 61,
            ],
        ];

        yield 'dashboard updated' => [
            new DashboardUpdated(41, 'service_post_completed', 52, 53, 61),
            'events.41',
            'dashboard.updated',
            [
                'event_id' => 41,
                'reason' => 'service_post_completed',
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'service_post_id' => 61,
            ],
        ];

        yield 'tv monitor updated' => [
            new TVMonitorUpdated(41, 'service_post_completed', 52, 53, 61),
            'events.41',
            'tv-monitor.updated',
            [
                'event_id' => 41,
                'reason' => 'service_post_completed',
                'event_participant_id' => 52,
                'queue_ticket_id' => 53,
                'service_post_id' => 61,
            ],
        ];
    }
}
