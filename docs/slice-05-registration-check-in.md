# Slice 5 — Registrasi Ulang

> Dokumen historis. Workflow, pilihan layanan, nomor registrasi, pembatalan, dan realtime aktif telah disempurnakan oleh Final Master Refactor. Gunakan [Final Business Workflow](business-workflow.md) dan [Database Documentation](database.md) sebagai sumber kebenaran.

## Fitur selesai

- Meja check-in untuk event aktif dengan pencarian dan autocomplete peserta.
- Relasi operasional `event_participants` yang menjaga identitas global peserta tetap terpisah dari status workflow per event.
- Penerbitan nomor antrean umum sesuai jumlah digit pada pengaturan event.
- Check-in idempotent: pengiriman ulang untuk peserta yang sama mengembalikan tiket yang sudah ada.
- Tiket antrean responsif dan siap cetak dengan tujuan Pos Pemeriksaan Kesehatan.
- Daftar 20 check-in terbaru yang diperbarui melalui broadcast Reverb `participant.checked-in`, tanpa polling.
- Audit log, policy `check-in.manage`, Form Request, transaction, row locking, dan database unique constraints.
- Guard penghapusan peserta yang sudah terhubung ke event.

## Keputusan desain

Kolom `type` pada pos pelayanan menjadi penanda workflow yang terkontrol melalui enum. Nama dan kode pos tetap bebas disesuaikan operator, sedangkan sistem dapat menemukan satu pos `health` secara aman tanpa bergantung pada teks. Pos lama menggunakan tipe `custom`, sehingga perubahan ini kompatibel dengan data Slice 3.

Nomor antrean dihitung di dalam transaksi setelah row event dikunci. Kombinasi lock event dan unique constraint `(event_id, queue_type, number)` mencegah dua transaksi menerbitkan nomor yang sama. Constraint `(event_id, participant_id)` dan `(event_participant_id, service_post_id)` menjaga check-in tetap idempotent pada lapisan database.

## Struktur utama

```text
app/
├── Data/CheckInResult.php
├── Enums/
│   ├── ParticipantStatus.php
│   ├── QueueTicketStatus.php
│   ├── QueueType.php
│   └── ServicePostType.php
├── Events/ParticipantCheckedIn.php
├── Http/Controllers/CheckIn/
├── Http/Requests/CheckIn/StoreCheckInRequest.php
├── Http/Resources/QueueTicketResource.php
├── Models/
│   ├── EventParticipant.php
│   └── QueueTicket.php
├── Policies/EventParticipantPolicy.php
└── Services/
    ├── CheckIn/CheckInService.php
    └── Workflow/ParticipantStateMachine.php

database/migrations/
├── 2026_07_29_000010_add_type_to_service_posts_table.php
├── 2026_07_29_000011_create_event_participants_table.php
└── 2026_07_29_000012_create_queue_tickets_table.php

resources/views/check-ins/
├── index.blade.php
└── show.blade.php
```

## Pengujian

```powershell
php artisan migrate
php artisan test --filter=CheckInTest
vendor\bin\pint --test
npm.cmd run build
npm.cmd audit --audit-level=high
```

## Risiko yang dikendalikan

- Row locking bergantung pada engine database transaksional; production wajib menggunakan InnoDB.
- SQLite lokal memverifikasi constraint dan alur, tetapi pengujian beban paralel final perlu dijalankan terhadap database production-like MySQL.
- Pos kesehatan tidak boleh dinonaktifkan saat meja check-in sedang digunakan. Check-in berikutnya akan ditolak secara eksplisit sampai pos aktif kembali.
