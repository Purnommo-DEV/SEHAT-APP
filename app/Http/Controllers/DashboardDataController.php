<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardDataController extends Controller
{
    public function __invoke(DashboardService $dashboardService): JsonResponse
    {
        return response()->json($dashboardService->snapshot());
    }
}
