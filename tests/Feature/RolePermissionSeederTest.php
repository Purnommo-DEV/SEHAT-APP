<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Models\User;
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
            ->where('email', config('foundation.seed_admin.email'))
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
            ->where('email', config('foundation.seed_admin.email'))
            ->firstOrFail();

        $this->assertTrue($administrator->hasPermissionTo(PermissionName::ViewDashboard->value));
    }

    public function test_reseeding_preserves_the_existing_administrator_password(): void
    {
        $administrator = User::factory()->create([
            'email' => config('foundation.seed_admin.email'),
            'password' => 'existing-administrator-password',
            'is_active' => false,
        ]);
        config()->set('foundation.seed_admin.password', null);

        $this->seed(RolePermissionSeeder::class);

        $administrator->refresh();

        $this->assertTrue(Hash::check('existing-administrator-password', $administrator->password));
        $this->assertTrue($administrator->is_active);
        $this->assertTrue($administrator->hasRole(UserRole::Administrator->value));
        $this->assertTrue($administrator->hasAllPermissions(PermissionName::values()));
    }
}
