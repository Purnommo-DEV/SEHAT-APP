<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'metrics' => [
                'events' => Event::query()->count(),
                'active_events' => Event::query()
                    ->where('status', EventStatus::Active->value)
                    ->count(),
                'participants' => Participant::query()->count(),
                'users' => User::query()->count(),
                'audit_logs' => AuditLog::query()->count(),
            ],
        ]);
    }
}
