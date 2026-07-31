# Slice 6 — Pemeriksaan Kesehatan

> Dokumen historis. Pemeriksaan kesehatan tidak lagi selalu mendahului screening; routing aktif mengikuti layanan yang dipilih peserta. Gunakan [Final Business Workflow](business-workflow.md) sebagai sumber kebenaran.

## Fitur selesai

- Meja antrean kesehatan untuk memanggil, melewati, memanggil ulang, dan memulai pelayanan.
- Lifecycle tiket tervalidasi: `waiting → calling → serving → finished` serta `waiting/calling → skipped → calling`.
- Form hasil pemeriksaan: tekanan darah, gula darah, kolesterol, asam urat, dan catatan.
- Koreksi hasil pemeriksaan yang sudah tersimpan dengan audit nilai sebelum dan sesudah.
- Penyelesaian atomik yang menyimpan pemeriksaan, menyelesaikan tiket, lalu menutup layanan kesehatan peserta sesuai workflow final.
- Daftar antrean dan 20 pemeriksaan terbaru yang diperbarui melalui Reverb tanpa polling.
- Policy `health.manage`, Form Request, row locking, scoped binding, audit log, dan validasi state transition.

## Struktur utama

```text
app/
├── Data/HealthAssessmentCompletionResult.php
├── Events/HealthQueueUpdated.php
├── Http/Controllers/Health/
├── Http/Requests/Health/
├── Http/Resources/HealthQueueTicketResource.php
├── Models/HealthAssessment.php
├── Policies/HealthAssessmentPolicy.php
└── Services/
    ├── Health/HealthQueueService.php
    └── Workflow/QueueTicketStateMachine.php

database/migrations/
└── 2026_07_29_000013_create_health_assessments_table.php

resources/views/health/
├── index.blade.php
└── assessment.blade.php
```

## Keputusan dan risiko

Data pemeriksaan memakai satu tabel eksplisit sesuai arsitektur final, bukan EAV. Field baru dapat ditambahkan dengan migration tanpa mengorbankan keterbacaan data saat ini.

Tiket dan peserta selalu dikunci di dalam transaksi. Penyelesaian ditolak bila tiket belum `serving` atau Pos Screening Donor tidak aktif, sehingga peserta tidak dapat terdampar di state yang tidak memiliki tujuan.

## Pengujian

```powershell
php artisan test --filter=HealthAssessmentTest
php artisan test
vendor\bin\pint --test
npm.cmd run build
```
