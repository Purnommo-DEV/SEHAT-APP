<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        /** @var LengthAwarePaginator<int, AuditLog> $auditLogs */
        $auditLogs = AuditLog::query()
            ->with([
                'user:id,name',
                'event:id,code,name',
            ])
            ->latest('created_at')
            ->paginate(30);

        return view('admin.audit-logs.index', compact('auditLogs'));
    }
}
