<?php

namespace App\Exports;

use App\Data\EventReportSnapshot;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class EventOperationalReportExport implements FromView, ShouldAutoSize, WithTitle
{
    public function __construct(private readonly EventReportSnapshot $snapshot) {}

    public function view(): View
    {
        return view('reports.exports.excel', ['snapshot' => $this->snapshot]);
    }

    public function title(): string
    {
        return 'Rekap Peserta';
    }
}
