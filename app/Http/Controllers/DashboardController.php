<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboardService): View
    {
        return view('dashboard', [
            'snapshot' => $dashboardService->snapshot(),
            'dataUrl' => route('dashboard.data'),
        ]);
    }
}
