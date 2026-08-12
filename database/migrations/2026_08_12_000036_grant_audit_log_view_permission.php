<?php

use App\Enums\PermissionName;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $administratorRoleId = DB::table('roles')
            ->where('name', UserRole::Administrator->value)
            ->where('guard_name', 'web')
            ->value('id');

        foreach ([PermissionName::AccessAdministration, PermissionName::ViewAuditLogs] as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission->value)
                ->where('guard_name', 'web')
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $permission->value,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($administratorRoleId !== null) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $administratorRoleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach ([PermissionName::AccessAdministration, PermissionName::ViewAuditLogs] as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission->value)
                ->where('guard_name', 'web')
                ->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
