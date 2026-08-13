<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceStatus;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalCallingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_called_donor_only_eligible_participant_moves_from_eligibility_to_health_check_then_donor(): void
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
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.call.target_label', 'Cek Kelayakan Donor')
            ->assertJsonPath('queue.positions.0.can_start_health_check', false)
            ->assertJsonPath('queue.positions.0.can_start_eligibility', true);

        try {
            $operations->startHealthCheck($event, $donorOnly->eventParticipant, $actor);
            $this->fail('Peserta donor saja tidak boleh masuk ke Cek Kesehatan.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['participant' => ['Peserta donor harus melalui tahap Cek Kelayakan Donor terlebih dahulu.']],
                $exception->errors(),
            );
        }

        $eligibility = $operations->startEligibility($event, $donorOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::WaitingScreening, $eligibility->status);
        $this->assertDatabaseHas('queue_tickets', [
            'id' => $donorOnly->queueTicket->id,
            'status' => QueueTicketStatus::Cancelled->value,
        ]);
        $healthCheck = $operations->markEligible($event, $donorOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $healthCheck->status);
        $operations->startDonation($event, $donorOnly->eventParticipant, $actor);
        $donorTicket = $donorOnly->eventParticipant->queueTickets()->latest('id')->firstOrFail();
        $this->assertSame('L001', $donorTicket->formattedNumber());
        $this->assertSame($donorOnly->eventParticipant->registration_number, $donorTicket->number);
        $operations->complete($event, $donorOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $donorOnly->eventParticipant->fresh()->status);

    }

    public function test_called_health_only_participant_finishes_without_donor_eligibility(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $queue = app(ServiceQueueService::class);
        $operations = app(OperationalWorkflowService::class);

        $healthOnly = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $queue->call($event, $healthPost, $healthOnly->queueTicket, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.call.target_label', 'Cek Kesehatan')
            ->assertJsonPath('queue.positions.0.can_start_health_check', true)
            ->assertJsonPath('queue.positions.0.can_start_eligibility', false);
        $operations->startHealthCheck($event, $healthOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $healthOnly->eventParticipant->fresh()->status);
        $operations->completeBeforeDonation($event, $healthOnly->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $healthOnly->eventParticipant->fresh()->status);
    }

    public function test_called_health_and_donor_eligible_participant_moves_from_eligibility_to_health_check_then_donor(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $queue = app(ServiceQueueService::class);
        $operations = app(OperationalWorkflowService::class);

        $both = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $queue->call($event, $healthPost, $both->queueTicket, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.call.target_label', 'Cek Kelayakan Donor')
            ->assertJsonPath('queue.positions.0.can_start_health_check', false)
            ->assertJsonPath('queue.positions.0.can_start_eligibility', true);

        $operations->startEligibility($event, $both->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::WaitingScreening, $both->eventParticipant->fresh()->status);
        $operations->markEligible($event, $both->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $both->eventParticipant->fresh()->status);
        $operations->startDonation($event, $both->eventParticipant, $actor);
        $bothDonorTicket = $both->eventParticipant->queueTickets()->latest('id')->firstOrFail();
        $this->assertSame('L001', $bothDonorTicket->formattedNumber());
        $this->assertSame($both->eventParticipant->registration_number, $bothDonorTicket->number);
        $operations->complete($event, $both->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $both->eventParticipant->fresh()->status);
    }

    public function test_called_donor_only_participant_can_be_cancelled_as_not_eligible_without_using_a_bed(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );

        app(ServiceQueueService::class)->call($event, $healthPost, $registration->queueTicket, $actor);
        $operations = app(OperationalWorkflowService::class);
        $operations->startEligibility($event, $registration->eventParticipant, $actor);
        $operations->markIneligible($event, $registration->eventParticipant, $actor);

        $this->assertSame(ParticipantStatus::Finished, $registration->eventParticipant->fresh()->status);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->eventParticipant->id,
            'service_post_id' => ServicePost::query()
                ->where('event_id', $event->id)
                ->where('behavior', ServicePostBehavior::DonationForm->value)
                ->value('id'),
        ]);
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Finished->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('data.0.eligibility.result', 'not_eligible');
    }

    public function test_called_health_and_donor_not_eligible_participant_continues_to_health_check_without_entering_donor(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $operations = app(OperationalWorkflowService::class);

        app(ServiceQueueService::class)->call($event, $healthPost, $registration->queueTicket, $actor);
        $operations->startEligibility($event, $registration->eventParticipant, $actor);
        $notEligible = $operations->markIneligible($event, $registration->eventParticipant, $actor);

        $this->assertSame(ParticipantStatus::WaitingScreening, $notEligible->status);
        $this->assertDatabaseHas('event_participant_services', [
            'event_participant_id' => $registration->eventParticipant->id,
            'service' => ParticipantServiceType::Donor->value,
            'status' => ParticipantServiceStatus::NotEligible->value,
        ]);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.eligibility.result', 'not_eligible')
            ->assertJsonPath('queue.positions.0.can_continue_to_health_check', true)
            ->assertJsonPath('queue.positions.0.can_donate', false);

        $healthCheck = $operations->startHealthCheck($event, $registration->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $healthCheck->status);
        $finished = $operations->completeBeforeDonation($event, $registration->eventParticipant, $actor);

        $this->assertSame(ParticipantStatus::Finished, $finished->status);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->eventParticipant->id,
            'service_post_id' => ServicePost::query()
                ->where('event_id', $event->id)
                ->where('behavior', ServicePostBehavior::DonationForm->value)
                ->value('id'),
        ]);
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
            ->assertJsonPath('queue.positions.0.call.target_label', 'Cek Kelayakan Donor')
            ->assertJsonPath('queue.tickets.0.status', QueueTicketStatus::Calling->value);

        $operations = app(OperationalWorkflowService::class);
        $operations->startEligibility($event, $registration->eventParticipant, $actor);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queue.tickets')
            ->assertJsonPath('queue.positions.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('queue.positions.0.status', ParticipantStatus::WaitingScreening->value);

        $operations->markEligible($event, $registration->eventParticipant, $actor);
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
        $operations = app(OperationalWorkflowService::class);
        $operations->startEligibility($event, $first->eventParticipant, $actor);
        $operations->markEligible($event, $first->eventParticipant, $actor);
        $operations->startDonation($event, $first->eventParticipant, $actor);
        $queue->call($event, $healthPost, $second->queueTicket, $actor);

        $this->assertSame(ParticipantStatus::Donating, $first->eventParticipant->fresh()->status);
        $this->assertSame(ParticipantStatus::Calling, $second->eventParticipant->fresh()->status);
    }

    public function test_next_uses_stable_global_registration_order_across_genders(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $first = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $second = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $third = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $this->assertSame([1, 2, 3], [
            $first->eventParticipant->registration_order,
            $second->eventParticipant->registration_order,
            $third->eventParticipant->registration_order,
        ]);

        $queue = app(ServiceQueueService::class);
        $firstCall = $queue->callNextWaiting($event, $healthPost, $actor);
        $this->assertSame($first->queueTicket->id, $firstCall->id);

        $queue->skip($event, $healthPost, $firstCall, $actor);
        $secondCall = $queue->callNextWaiting($event, $healthPost, $actor);
        $this->assertSame($second->queueTicket->id, $secondCall->id);
        $this->assertSame(ParticipantStatus::Waiting, $third->eventParticipant->fresh()->status);
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
            'type' => ServicePostType::Screening,
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 2,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 3,
            'is_active' => true,
        ]);

        return [$actor, $event->fresh('settings'), $healthPost];
    }
}
