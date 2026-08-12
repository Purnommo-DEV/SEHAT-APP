<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_public_for_operational_panitia(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada event aktif');
    }

    public function test_dashboard_stays_public_for_an_authenticated_user_without_permissions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_authorized_user_can_view_dashboard(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ViewDashboard->value,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Belum ada event aktif');
    }

    public function test_event_management_remains_protected_by_authentication_and_permission(): void
    {
        $this->get(route('events.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.index'))
            ->assertForbidden();
    }
}
