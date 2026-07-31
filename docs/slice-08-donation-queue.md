# Slice 8 — Antrean Donor

> Dokumen historis. Nomor donor kini mendukung mode global atau terpisah gender dan memakai reuse angka aktif terkecil. Gunakan [Final Business Workflow](business-workflow.md) dan [Database Documentation](database.md).

## Fitur selesai

- Penerbitan nomor donor otomatis di dalam transaksi keputusan `Layak Donor`.
- Counter independen per event dan gender: `L001, L002, ...` serta `P001, P002, ...`.
- Prefix donor mengambil pengaturan event, bukan hardcode tampilan.
- Meja donor dua kolom untuk laki-laki dan perempuan.
- Lifecycle panggil, lewati, panggil ulang, mulai donor, dan donor selesai.
- Penyelesaian atomik mengubah tiket menjadi `finished`, peserta menjadi `DONATION_COMPLETED`, dan mengarahkannya ke Pos Selesai bila tersedia.
- Policy `donation.manage`, Form Request, scoped binding, row locking, audit log, dan broadcast `donor.queue.updated`.
- Index gabungan untuk query antrean per event, tipe, status, dan nomor.

## Struktur utama

```text
app/
├── Events/DonorQueueUpdated.php
├── Http/Controllers/Donation/
├── Http/Requests/Donation/DonationQueueActionRequest.php
├── Http/Resources/DonorQueueTicketResource.php
├── Policies/QueueTicketPolicy.php
└── Services/
    ├── Donation/DonorQueueService.php
    └── Queue/QueueNumberGenerator.php

database/migrations/
└── 2026_07_29_000015_add_donor_queue_lookup_index.php

resources/views/
├── components/donor-queue-column.blade.php
└── donation/index.blade.php
```

## Race-condition safety

`QueueNumberGenerator` hanya dipanggil setelah row event dikunci. Unique constraint `(event_id, queue_type, number)` menjadi pertahanan database terakhir. Keputusan screening, perubahan state peserta, dan pembuatan tiket donor berada dalam transaksi yang sama, sehingga status `WAITING_DONOR` tidak dapat tersimpan tanpa tiket.

## Pengujian

```powershell
php artisan test --filter=DonationQueueTest
php artisan test
vendor\bin\pint --test
npm.cmd run build
npm.cmd audit --audit-level=high
```
