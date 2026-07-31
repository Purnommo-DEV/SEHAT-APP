# Slice 2 — Master Event

Slice ini menjadikan event sebagai konteks operasional utama aplikasi.

## Fitur selesai

- CRUD event dengan kode unik, jadwal, lokasi, dan keterangan.
- Lifecycle event: `draft` → `active` → `completed`, atau `draft`/`active` → `cancelled`.
- Hanya satu event dapat aktif pada satu waktu.
- Pengaturan antrean per event: digit nomor umum serta prefix donor laki-laki/perempuan.
- Policy `events.manage`, form request, service transaksional, audit log, dan route model binding.
- Broadcast Reverb `event.lifecycle.updated` pada setiap perubahan event atau pengaturannya.
- Halaman daftar event diperbarui melalui Echo saat broadcast diterima; tidak menggunakan polling atau refresh halaman.
- Konfirmasi aksi status dengan SweetAlert2 dan flash notification dengan Toastr.

## Keputusan desain

`events.active_marker` adalah nilai nullable yang unik. Event aktif bernilai `active`; event lain bernilai `null`. Karena MySQL dan SQLite mengizinkan banyak nilai `NULL` pada unique index, constraint ini menjamin hanya satu event aktif bahkan jika dua panitia mencoba mengaktifkan event pada waktu bersamaan. `EventService` tetap memakai database transaction dan row lock agar pesan kegagalan bisnis jelas.

`event_settings` memiliki relasi satu-banding-satu dengan event. Tiga pengaturan di dalamnya adalah kebijakan antrean yang nyata dan akan dipakai langsung oleh Slice Registrasi Ulang serta Antrean Donor, tanpa membuat tabel konfigurasi generik atau EAV.

`audit_logs` menyimpan aktor, aksi, subjek, nilai sebelum, dan nilai sesudah. Catatan dibuat sejak perubahan event pertama sehingga timeline realtime pada Slice Dashboard dapat menggunakan data nyata.

## Struktur utama

```text
app/
├── Enums/EventStatus.php
├── Enums/AuditAction.php
├── Events/EventLifecycleUpdated.php
├── Http/Controllers/Event/
├── Http/Requests/Event/
├── Http/Resources/EventResource.php
├── Models/{Event,EventSetting,AuditLog}.php
├── Policies/EventPolicy.php
└── Services/Event/EventService.php

database/migrations/
├── 2026_07_29_000005_create_events_table.php
├── 2026_07_29_000006_create_event_settings_table.php
└── 2026_07_29_000007_create_audit_logs_table.php

resources/views/events/
├── index.blade.php
├── create.blade.php
├── edit.blade.php
├── show.blade.php
└── settings.blade.php
```

## Menjalankan dan menguji

```powershell
php artisan migrate
npm install
npm run build
php artisan reverb:start
php artisan test
```

Untuk pengembangan frontend gunakan `npm run dev`. Reverb harus berjalan agar pembaruan daftar event lintas-client diterima saat perubahan disimpan.

## Batasan yang disengaja

Event yang sudah aktif, selesai, atau dibatalkan tidak dapat diubah. Hal ini mencegah perubahan kebijakan nomor antrean setelah data operasional mulai tercipta. Event draf dapat diperbarui atau dihapus. Slice berikutnya dapat mulai dari daftar Pos Pelayanan dan akan selalu mengaitkan data pos dengan event.
