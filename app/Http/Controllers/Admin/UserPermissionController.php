<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserPermissionController extends Controller
{
    public function index(): View
    {
        /** @var LengthAwarePaginator<int, User> $users */
        $users = User::query()
            ->with(['roles.permissions', 'permissions'])
            ->orderBy('name')
            ->paginate(20);
        $roles = Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get();
        $permissions = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.users.index', compact('users', 'roles', 'permissions'));
    }
}
