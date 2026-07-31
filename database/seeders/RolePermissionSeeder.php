<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $administratorEmail = $this->administratorEmail();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () use ($administratorEmail): void {
            foreach (PermissionName::cases() as $permission) {
                Permission::query()->firstOrCreate([
                    'name' => $permission->value,
                    'guard_name' => 'web',
                ]);
            }

            $administratorRole = $this->syncRole(UserRole::Administrator, PermissionName::values());
            $this->syncRole(UserRole::RegistrationCommittee, [
                PermissionName::ManageParticipants->value,
                PermissionName::ManageCheckIn->value,
            ]);
            $this->syncRole(UserRole::HealthCommittee, [
                PermissionName::ManageHealth->value,
                PermissionName::ManageOwnQueue->value,
            ]);
            $this->syncRole(UserRole::ScreeningCommittee, [
                PermissionName::ManageScreening->value,
                PermissionName::ManageOwnQueue->value,
            ]);
            $this->syncRole(UserRole::DonationCommittee, [
                PermissionName::ManageDonation->value,
                PermissionName::ManageOwnQueue->value,
            ]);
            $this->syncRole(UserRole::Viewer, [
                PermissionName::ViewMonitor->value,
            ]);

            $administrator = User::query()
                ->where('email', $administratorEmail)
                ->first();

            if ($administrator === null) {
                $administrator = User::query()->create([
                    'email' => $administratorEmail,
                    'name' => config('foundation.seed_admin.name'),
                    'password' => $this->administratorPassword(),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]);
            } else {
                $administrator->forceFill([
                    'name' => config('foundation.seed_admin.name'),
                    'email_verified_at' => $administrator->email_verified_at ?? now(),
                    'is_active' => true,
                ])->save();
            }

            $administrator->syncRoles([$administratorRole]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function administratorPassword(): string
    {
        $password = (string) config('foundation.seed_admin.password');
        $blockedPasswords = [
            'password',
            'password123',
            'administrator',
            'password',
        ];

        if (mb_strlen($password) < 12 || in_array(mb_strtolower($password), $blockedPasswords, true)) {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD wajib diisi dengan minimal 12 karakter dan tidak boleh menggunakan kata sandi umum.',
            );
        }

        return $password;
    }

    private function administratorEmail(): string
    {
        $email = filter_var(config('foundation.seed_admin.email'), FILTER_VALIDATE_EMAIL);

        if (! is_string($email)) {
            throw new RuntimeException('SEED_ADMIN_EMAIL wajib diisi dengan alamat email yang valid.');
        }

        return $email;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function syncRole(UserRole $roleName, array $permissions): Role
    {
        $role = Role::query()->firstOrCreate([
            'name' => $roleName->value,
            'guard_name' => 'web',
        ]);
        $permissionModels = Permission::query()
            ->whereIn('name', $permissions)
            ->where('guard_name', 'web')
            ->get();

        $role->syncPermissions($permissionModels);

        return $role;
    }
}
