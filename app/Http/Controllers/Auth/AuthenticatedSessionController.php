<?php

namespace App\Http\Controllers\Auth;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuthenticationService $authentication): RedirectResponse
    {
        $authentication->authenticate(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            remember: $request->boolean('remember'),
            ipAddress: $request->ip() ?? 'unknown',
            session: $request->session(),
        );

        $destination = match (true) {
            $request->user()->can(PermissionName::AccessAdministration->value) => route('admin.dashboard'),
            $request->user()->can(PermissionName::ViewDashboard->value) => route('dashboard'),
            $request->user()->can(PermissionName::ManageCheckIn->value) => route('check-ins.active'),
            $request->user()->can(PermissionName::ManageOwnQueue->value) => route('queues.active'),
            $request->user()->can(PermissionName::ViewMonitor->value) => route('monitor.active'),
            default => route('dashboard'),
        };

        return redirect()->intended($destination);
    }

    public function destroy(Request $request, AuthenticationService $authentication): RedirectResponse
    {
        $authentication->logout($request->session());

        return redirect()->route('login')->with('status', 'Anda telah keluar dengan aman.');
    }
}
