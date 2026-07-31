# Slice 3 — Master Pos Pelayanan

Slice ini membangun jalur pelayanan yang selalu dimiliki oleh sebuah event. Pos dapat digunakan untuk alur donor darah maupun kegiatan sosial lain tanpa mengubah struktur inti.

## Fitur selesai

- CRUD Pos Pelayanan di bawah event.
- Kode pos unik per event, nama, keterangan, urutan, dan status aktif.
- Pos baru ditempatkan di urutan terakhir.
- Aksi naik/turun menukar urutan secara aman di dalam database transaction.
- Pengaturan pos dibatasi pada event `draft`; saat event `active`, panitia hanya dapat mengaktifkan atau menonaktifkan pos.
- Scoped route model binding mencegah pos sebuah event diakses melalui URL event lain.
- Audit log untuk create, update, reorder, activate/deactivate, dan delete.
- Broadcast Reverb `service-post.updated` ke channel private `events.{eventId}`.
- Daftar pos memperbarui data melalui Echo saat menerima broadcast, tanpa polling atau refresh halaman.

## Keputusan desain

Tabel `service_posts` memakai `event_id`, `code`, dan `sequence`. Kedua kombinasi `(event_id, code)` serta `(event_id, sequence)` unik. Semua perubahan pos mengunci baris event terlebih dahulu; ini membuat penambahan dan pertukaran urutan pada event yang sama berjalan serial. Ketika dua sequence ditukar, service menggunakan sequence sementara agar unique constraint tidak pernah dilanggar di tengah transaksi.

`is_active` adalah satu flag operasional, bukan status proses peserta. Status proses peserta tetap akan ditangani oleh state transition pada slice alur pelayanan. Pos aktif/nonaktif dipisahkan agar panitia dapat menutup pos sementara di hari H tanpa mengubah jalur data atau status peserta.

## Struktur utama

```text
app/
├── Enums/ServicePostMoveDirection.php
├── Events/ServicePostUpdated.php
├── Http/Controllers/ServicePost/
├── Http/Requests/ServicePost/
├── Http/Resources/ServicePostResource.php
├── Models/ServicePost.php
├── Policies/ServicePostPolicy.php
└── Services/
    ├── Audit/AuditLogger.php
    └── ServicePost/ServicePostService.php

database/migrations/
└── 2026_07_29_000008_create_service_posts_table.php

resources/views/service-posts/
├── index.blade.php
├── create.blade.php
└── edit.blade.php
```

## Pengujian

```powershell
php artisan migrate
php artisan test --filter=ServicePostManagementTest
npm run build
php artisan reverb:start
```

Test mencakup authorization, CRUD, urutan tanpa duplikasi, toggle pada event aktif, scoped binding antar-event, audit, broadcast, halaman, dan endpoint realtime.

## Batasan yang disengaja

Pada event aktif, urutan dan data pos dikunci agar peserta yang sudah berjalan tidak berpindah ke rute berbeda. Hanya status aktif/nonaktif yang boleh berubah. Slice Peserta dan Registrasi Ulang berikutnya akan memilih event aktif dan pos aktif sebagai konteks operasional.
