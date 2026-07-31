# Slice 9 — Dashboard Realtime

> Dokumen historis. Metric serta kontrak event canonical terbaru dijelaskan pada [Architecture Diagram](architecture.md) dan [Final Business Workflow](business-workflow.md).

## Fitur selesai

- Dashboard event aktif dengan delapan indikator operasional: total peserta, check-in, menunggu/berjalan kesehatan, screening, donor, donor selesai, dan tidak layak.
- Daftar posisi peserta berdasarkan pos dan state saat ini.
- Timeline audit 30 aktivitas terbaru, termasuk pelaku, peserta/subject, waktu, dan label aksi.
- Endpoint JSON dashboard yang efisien dengan eager loading polymorphic untuk menghindari N+1 query.
- Listener Echo untuk lifecycle event dan seluruh perubahan operasional pada event aktif; UI diperbarui tanpa polling.
- Index database untuk agregasi status dashboard.

## Struktur utama

```text
app/
├── Http/Controllers/DashboardDataController.php
└── Services/Dashboard/DashboardService.php

database/migrations/
└── 2026_07_29_000016_add_dashboard_status_index.php

resources/views/dashboard.blade.php
```

## Pengujian

```powershell
php artisan test --filter=Dashboard
vendor\bin\pint --test
php artisan migrate
```
