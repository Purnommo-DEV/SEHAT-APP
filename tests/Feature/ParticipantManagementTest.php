<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantGender;
use App\Enums\UserRole;
use App\Events\ParticipantUpdated;
use App\Models\Participant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class ParticipantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_manager_can_create_search_autocomplete_update_and_delete_participant(): void
    {
        EventFacade::fake([ParticipantUpdated::class]);
        $user = $this->registrationCommittee();

        $this->actingAs($user)
            ->post(route('participants.store'), [
                'name' => '  Siti   Aminah ',
                'phone' => '0812-3456-7890',
                'nik' => '3273 0123 4567 8901',
                'gender' => ParticipantGender::Female->value,
                'birth_date' => '1990-01-01',
                'address' => 'Jl. Sehat',
            ])
            ->assertRedirect(route('participants.index'));

        $participant = Participant::query()->where('nik', '3273012345678901')->firstOrFail();
        $this->assertSame('Siti Aminah', $participant->name);
        $this->assertSame('081234567890', $participant->phone);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Participant::class,
            'subject_id' => $participant->id,
            'action' => AuditAction::ParticipantCreated->value,
        ]);
        EventFacade::assertDispatched(ParticipantUpdated::class);

        $this->actingAs($user)
            ->getJson(route('participants.data', ['q' => '0812']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participant->id);

        $this->actingAs($user)
            ->getJson(route('participants.autocomplete', ['q' => 'Siti']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Siti Aminah');

        $this->actingAs($user)
            ->put(route('participants.update', $participant), [
                'name' => 'Siti Aminah', 'phone' => '081234567890', 'nik' => '3273012345678901',
                'gender' => ParticipantGender::Female->value, 'birth_date' => '1990-01-01', 'address' => 'Alamat Baru',
            ])
            ->assertRedirect(route('participants.index'));

        $this->actingAs($user)
            ->delete(route('participants.destroy', $participant))
            ->assertRedirect(route('participants.index'));
        $this->assertDatabaseMissing('participants', ['id' => $participant->id]);
    }

    public function test_participant_pages_are_authorized_and_rendered(): void
    {
        $participant = Participant::factory()->create();
        $this->get(route('participants.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('participants.index'))->assertForbidden();

        $user = $this->registrationCommittee();
        $this->actingAs($user)->get(route('participants.index'))->assertOk()->assertSee('Master Peserta');
        $this->actingAs($user)->get(route('participants.edit', $participant))->assertOk()->assertSee($participant->name);
        $this->actingAs($user)->getJson(route('participants.autocomplete', ['q' => 'x']))->assertUnprocessable();
    }

    public function test_live_search_orders_by_relevance_and_limits_results_to_ten(): void
    {
        $user = $this->registrationCommittee();
        Participant::factory()->create(['name' => 'Siti', 'phone' => '081200000001']);
        Participant::factory()->create(['name' => 'Siti Aminah', 'phone' => '081200000002']);
        Participant::factory()->create(['name' => 'Ibu Siti', 'phone' => '081200000003']);
        Participant::factory()->count(10)->sequence(
            fn ($sequence): array => [
                'name' => "Siti Peserta {$sequence->index}",
                'phone' => '0821'.str_pad((string) $sequence->index, 8, '0', STR_PAD_LEFT),
            ],
        )->create();

        $response = $this->actingAs($user)
            ->getJson(route('participants.autocomplete', ['q' => 'Siti']))
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertSame('Siti', $response->json('data.0.name'));
        $this->assertSame('Siti Aminah', $response->json('data.1.name'));
    }

    private function registrationCommittee(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(UserRole::RegistrationCommittee->value);

        return $user;
    }
}
