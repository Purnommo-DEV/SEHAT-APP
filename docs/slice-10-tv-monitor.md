# Slice 10 — TV Monitor

> Dokumen historis. TV Monitor aktif mengikuti Pos Pelayanan dan layanan peserta, bukan tiga panel antrean legacy. Gunakan [Final Business Workflow](business-workflow.md) dan [Architecture Diagram](architecture.md).

## Fitur selesai

- Layar monitor read-only untuk event aktif.
- Tiga panel besar: antrean umum, donor laki-laki, dan donor perempuan.
- Nomor yang sedang dipanggil, nama peserta, serta lima nomor berikutnya per kelompok antrean.
- Layout kontras tinggi yang dapat dibaca dari kejauhan dan responsif hingga layar TV.
- Mode fullscreen melalui klik ganda atau F11 tanpa menambah tombol operasional.
- Policy `monitor.view`, scoped route event aktif, endpoint JSON, dan listener Echo tanpa polling.
- Index database khusus query tiket yang sedang dipanggil.

## Struktur utama

```text
app/
├── Http/Controllers/Monitor/
├── Models/Monitor.php
├── Policies/MonitorPolicy.php
└── Services/Monitor/MonitorService.php

database/migrations/
└── 2026_07_29_000017_add_monitor_queue_index.php

resources/views/
├── layouts/monitor.blade.php
└── monitor/show.blade.php
```

## Pengujian

```powershell
php artisan test --filter=MonitorTest
vendor\bin\pint --test
npm.cmd run build
```
