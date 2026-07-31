# Slice 11 — Laporan

> Dokumen historis. Field layanan, nomor, timestamp, serta metric report final mengikuti [Database Documentation](database.md) dan [Final Business Workflow](business-workflow.md).

## Fitur selesai

- Pemilihan event untuk laporan tanpa mengasumsikan hanya satu kegiatan.
- Statistik operasional lengkap serta rekap peserta: kontak, antrean, pos/status, hasil kesehatan, screening, dan penyelesaian donor.
- Endpoint laporan JSON dan pembaruan realtime untuk event yang sedang dilihat.
- Unduhan Excel `.xlsx` aktual menggunakan Laravel Excel / PhpSpreadsheet.
- Unduhan PDF aktual menggunakan DomPDF dengan format A4 landscape.
- Policy `reports.view`, Form Request, eager loading relasi laporan, dan index hasil screening per event.

## Dependensi

- `maatwebsite/excel 3.1.69`
- `barryvdh/laravel-dompdf 3.1.2`

## Struktur utama

```text
app/
├── Data/EventReportSnapshot.php
├── Exports/EventOperationalReportExport.php
├── Http/Controllers/Report/ReportController.php
├── Http/Requests/Report/ReportQueryRequest.php
├── Models/Report.php
├── Policies/ReportPolicy.php
└── Services/Report/ReportService.php

database/migrations/
└── 2026_07_29_000018_add_report_screening_index.php

resources/views/reports/
├── index.blade.php
└── exports/
    ├── excel.blade.php
    └── pdf.blade.php
```

## Pengujian

```powershell
php artisan test --filter=ReportTest
vendor\bin\pint --test
composer validate --strict
npm.cmd run build
npm.cmd audit --audit-level=high
```

Pengujian laporan menjalankan exporter asli dan memverifikasi respons XLSX serta signature PDF, bukan export mock.
