<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Operational\OperationalWorkflowService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalCallingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_calling_routes_each_selected_service_without_creating_a_second_donor_number(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $queue = app(ServiceQueueService::class);
        $operations = app(OperationalWorkflowService::class);

        $donorOnly = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $queue->call($event, $healthPost, $donorOnly->queueTicket, $actor);
        $this->assertSame(ParticipantStatus::Calling, $donorOnly->eventParticipant->fresh()->status);

        $operations->startDonation($event, $donorOnly->eventParticipant, $actor);
        $donorTicket = $donorOnly->eventParticipant->queueTickets()->latest('id')->firstOrFail();
        $this->assertSame('L001', $donorTicket->formattedNumber());
        $this->assertSame($donorOnly->eventParticipant->registration_number, $donorTicket->number);
        $operations->complete($event, $donorOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $donorOnly->eventParticipant->fresh()->status);

        $healthOnly = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $queue->call($event, $healthPost, $healthOnly->queueTicket, $actor);
        $operations->startHealthCheck($event, $healthOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $healthOnly->eventParticipant->fresh()->status);
        $operations->completeBeforeDonation($event, $healthOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $healthOnly->eventParticipant->fresh()->status);

        $both = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $queue->call($event, $healthPost, $both->queueTicket, $actor);
        $operations->startHealthCheck($event, $both->eventParticipant, $actor);
        $operations->startDonation($event, $both->eventParticipant, $actor);
        $bothDonorTicket = $both->eventParticipant->queueTickets()->latest('id')->firstOrFail();
        $this->assertSame('L002', $bothDonorTicket->formattedNumber());
        $this->assertSame($both->eventParticipant->registration_number, $bothDonorTicket->number);
        $operations->complete($event, $both->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $both->eventParticipant->fresh()->status);
    }

    public function test_called_participant_remains_visible_after_its_control_ticket_is_closed(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );

        app(ServiceQueueService::class)->call($event, $healthPost, $registration->queueTicket, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('queue.positions.0.status', ParticipantStatus::Calling->value)
            ->assertJsonPath('queue.positions.0.call.target_label', 'Cek Kesehatan')
            ->assertJsonPath('queue.tickets.0.status', QueueTicketStatus::Calling->value);

        $operations = app(OperationalWorkflowService::class);
        $operations->startHealthCheck($event, $registration->eventParticipant, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queue.tickets')
            ->assertJsonPath('queue.positions.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('queue.positions.0.status', ParticipantStatus::HealthCheck->value);

        $operations->startDonation($event, $registration->eventParticipant, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('queue.positions.0.status', ParticipantStatus::Donating->value);
    }

    public function test_next_can_call_another_participant_when_the_previous_one_is_already_donating(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $first = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $second = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );

        $queue = app(ServiceQueueService::class);
        $queue->call($event, $healthPost, $first->queueTicket, $actor);
        app(OperationalWorkflowService::class)->startDonation($event, $first->eventParticipant, $actor);
        $queue->call($event, $healthPost, $second->queueTicket, $actor);

        $this->assertSame(ParticipantStatus::Donating, $first->eventParticipant->fresh()->status);
        $this->assertSame(ParticipantStatus::Calling, $second->eventParticipant->fresh()->status);
    }

    /** @return array{User, Event, ServicePost} */
    private function workflow(): array
    {
        $actor = User::factory()->create();
        $event = Event::factory()->active()->for($actor, 'creator')->create();
        $healthPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 2,
            'is_active' => true,
        ]);

        return [$actor, $event->fresh('settings'), $healthPost];
    }
}
