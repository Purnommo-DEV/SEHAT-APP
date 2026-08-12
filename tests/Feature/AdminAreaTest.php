<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_login_redirects_to_visible_administration_area(): void
    {
        $administrator = $this->administrator(['password' => 'password']);

        $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('Event')
            ->assertSee('Peserta')
            ->assertSee('User & Permission', false)
            ->assertSee('Audit Log')
            ->assertSee('Operasional');
    }

    public function test_administrator_can_open_event_settings_with_database_capacity_and_reset_danger_zone(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->for($administrator, 'creator')->create();
        $event->settings()->update([
            'donation_capacity_male' => 5,
            'donation_capacity_female' => 3,
        ]);

        $this->actingAs($administrator)
            ->get(route('events.settings.edit', $event))
            ->assertOk()
            ->assertSee($event->name)
            ->assertSee('Status event')
            ->assertSee('Jumlah bed laki-laki')
            ->assertSee('maleCapacity: Number(5)', false)
            ->assertSee('femaleCapacity: Number(3)', false)
            ->assertSee('Total kapasitas event')
            ->assertSee('Danger Zone')
            ->assertSee('Reset Antrean Event')
            ->assertSee('Ketik <span class="font-mono text-rose-700">RESET</span>', false);
    }

    public function test_administrator_can_reach_read_only_event_settings_for_an_active_event(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->for($administrator, 'creator')->create();

        $this->actingAs($administrator)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee(route('events.settings.edit', $event), false)
            ->assertSee('Pengaturan Event');

        $this->actingAs($administrator)
            ->get(route('events.settings.edit', $event))
            ->assertOk()
            ->assertSee('Konfigurasi event aktif ditampilkan sebagai referensi dan tidak dapat diubah.')
            ->assertSee('<fieldset disabled class="contents">', false)
            ->assertDontSee('Simpan pengaturan');
    }

    public function test_user_permission_and_audit_pages_are_authorized_and_eager_loaded_for_administrator(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User & Permission')
            ->assertSee('event.reset_queue')
            ->assertSee('audit-logs.view');
        $this->actingAs($administrator)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('Audit Log');
    }

    public function test_operational_user_cannot_open_admin_area_or_see_admin_navigation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create(['password' => 'password']);
        $operator->assignRole(UserRole::RegistrationCommittee->value);

        $this->post(route('login.store'), [
            'email' => $operator->email,
            'password' => 'password',
        ])->assertRedirect(route('check-ins.active'));

        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Administration')
            ->assertDontSee('User & Permission');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function administrator(array $attributes = []): User
    {
        $this->seed(RolePermissionSeeder::class);

        $administrator = User::factory()->create($attributes);
        $administrator->assignRole(UserRole::Administrator->value);

        return $administrator;
    }
}
