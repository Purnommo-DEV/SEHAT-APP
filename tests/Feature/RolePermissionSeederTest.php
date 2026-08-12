<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_all_roles_permissions_and_administrator(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(UserRole::cases()), Role::query()->count());
        $this->assertSame(count(PermissionName::cases()), Permission::query()->count());

        $administrator = User::query()
            ->where('email', AdminSeeder::EMAIL)
            ->firstOrFail();

        $this->assertTrue($administrator->hasRole(UserRole::Administrator->value));
        $this->assertTrue($administrator->hasAllPermissions(PermissionName::values()));
        $this->assertTrue($administrator->is_active);
    }

    public function test_operational_roles_receive_only_relevant_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $registrationRole = Role::findByName(UserRole::RegistrationCommittee->value);

        $this->assertTrue($registrationRole->hasPermissionTo(PermissionName::ManageCheckIn->value, 'web'));
        $this->assertFalse($registrationRole->hasPermissionTo(PermissionName::ManageHealth->value, 'web'));
    }

    public function test_seeder_works_with_database_cache_store(): void
    {
        config()->set('cache.default', 'database');

        $this->seed(RolePermissionSeeder::class);

        $administrator = User::query()
            ->where('email', AdminSeeder::EMAIL)
            ->firstOrFail();

        $this->assertTrue($administrator->hasPermissionTo(PermissionName::ViewDashboard->value));
    }

    public function test_reseeding_resets_the_existing_administrator_credentials_and_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $administrator = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();
        $administrator->forceFill([
            'password' => 'existing-administrator-password',
            'is_active' => false,
        ])->save();
        $administrator->syncRoles([UserRole::Viewer->value]);

        $this->seed(RolePermissionSeeder::class);

        $administrator->refresh();

        $this->assertTrue(Hash::check(AdminSeeder::PASSWORD, $administrator->password));
        $this->assertTrue($administrator->is_active);
        $this->assertTrue($administrator->hasRole(UserRole::Administrator->value));
        $this->assertTrue($administrator->hasAllPermissions(PermissionName::values()));
        $this->assertSame([UserRole::Administrator->value], $administrator->getRoleNames()->all());
    }

    public function test_seeded_administrator_can_log_in_with_the_specified_credentials(): void
    {
        $this->seed(AdminSeeder::class);

        $this->post(route('login.store'), [
            'email' => AdminSeeder::EMAIL,
            'password' => AdminSeeder::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
    }
}
