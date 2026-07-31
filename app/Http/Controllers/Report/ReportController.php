<?php

namespace App\Http\Controllers\Report;

use App\Data\EventReportSnapshot;
use App\Exports\EventOperationalReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportQueryRequest;
use App\Models\Event;
use App\Services\Report\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(ReportQueryRequest $request, ReportService $reportService): View
    {
        $event = $reportService->resolveEvent($request->validated('event_id'));
        $snapshot = $event === null ? null : $reportService->snapshot($event);

        return view('reports.index', [
            'events' => $this->events(),
            'snapshot' => $snapshot,
        ]);
    }

    public function data(ReportQueryRequest $request, ReportService $reportService): JsonResponse
    {
        $event = $reportService->resolveEvent($request->validated('event_id'));

        return response()->json($event === null ? null : $reportService->snapshot($event)->toArray());
    }

    public function excel(
        ReportQueryRequest $request,
        ReportService $reportService,
    ): BinaryFileResponse {
        $snapshot = $this->snapshotFromRequest($request, $reportService);

        return Excel::download(
            new EventOperationalReportExport($snapshot),
            $reportService->filename($snapshot, 'xlsx'),
        );
    }

    public function pdf(ReportQueryRequest $request, ReportService $reportService): Response
    {
        $snapshot = $this->snapshotFromRequest($request, $reportService);
        $pdf = Pdf::loadView('reports.exports.pdf', [
            'snapshot' => $snapshot,
            'generatedAt' => Date::now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($reportService->filename($snapshot, 'pdf'));
    }

    /**
     * @return Collection<int, Event>
     */
    private function events(): Collection
    {
        return Event::query()
            ->orderByRaw("case when active_marker = 'active' then 0 else 1 end")
            ->orderByDesc('starts_at')
            ->get(['id', 'code', 'name', 'status', 'active_marker', 'starts_at']);
    }

    private function snapshotFromRequest(
        ReportQueryRequest $request,
        ReportService $reportService,
    ): EventReportSnapshot {
        $eventId = $request->validated('event_id');
        $event = $eventId === null
            ? null
            : $reportService->resolveEvent($eventId);

        abort_if($event === null, 404, 'Pilih event sebelum mengunduh laporan.');

        return $reportService->snapshot($event);
    }
}
