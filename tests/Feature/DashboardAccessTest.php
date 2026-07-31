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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_user_without_dashboard_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
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
}
