# Slice 7 — Screening Donor

> Dokumen historis. Kontrak workflow dan broadcast final tersedia di [Final Business Workflow](business-workflow.md) dan [Architecture Diagram](architecture.md).

## Fitur selesai

- Meja screening realtime yang menerima peserta setelah pemeriksaan kesehatan selesai.
- Keputusan `Layak Donor` atau `Tidak Layak` dengan alasan opsional.
- Ringkasan hasil kesehatan pada kartu peserta tanpa menyimpan data medis tambahan dari PMI.
- Keputusan idempotent dan tidak dapat ditimpa oleh pengiriman form berulang.
- Peserta layak dipindahkan ke Pos Donor aktif; peserta tidak layak menjadi hasil terminal dan diarahkan ke Pos Selesai bila tersedia.
- Policy `screening.manage`, Form Request, scoped binding, transaction, row locking, audit log, dan broadcast `screening.updated`.

## Struktur utama

```text
app/
├── Data/ScreeningDecisionResult.php
├── Enums/ScreeningResult.php
├── Events/ScreeningUpdated.php
├── Http/Controllers/Screening/
├── Http/Requests/Screening/StoreScreeningRequest.php
├── Http/Resources/ScreeningParticipantResource.php
├── Models/DonorScreening.php
├── Policies/DonorScreeningPolicy.php
└── Services/Screening/DonorScreeningService.php

database/migrations/
└── 2026_07_29_000014_create_donor_screenings_table.php
```

## Batas vertical slice

Slice ini menuntaskan pencatatan keputusan dan perpindahan workflow. Penerbitan nomor L/P serta lifecycle meja donor berada pada Slice 8 dan diintegrasikan ke transaksi keputusan layak agar invariant `WAITING_DONOR` selalu memiliki tiket donor pada hasil akhir Slice 8.

## Pengujian

```powershell
php artisan test --filter=DonorScreeningTest
vendor\bin\pint --test
npm.cmd run build
```
