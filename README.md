# SEHAT-APP

SEHAT-APP adalah aplikasi operasional panitia donor darah dan pemeriksaan kesehatan. Aplikasi mengikuti SOP lapangan: registrasi, pemilihan layanan, pemeriksaan kelayakan donor, donor bagi peserta yang layak, pemeriksaan kesehatan sesuai pilihan peserta, laporan, dashboard, dan TV Monitor realtime.

Dokumen ini dan dokumen final di folder [`docs`](docs) adalah sumber kebenaran implementasi. Dokumen `slice-*` dipertahankan sebagai riwayat pengembangan dan bukan definisi workflow aktif.

## Workflow resmi

Peserta memperoleh satu nomor registrasi dari urutan numerik global event, kemudian memilih satu atau kedua layanan:

- Donor Darah
- Pemeriksaan Kesehatan

Alurnya:

- Donor saja: registrasi → kelayakan donor → donor jika layak → selesai.
- Pemeriksaan kesehatan saja: registrasi → pemeriksaan kesehatan → selesai.
- Donor dan pemeriksaan kesehatan: registrasi → kelayakan donor → donor jika layak → pemeriksaan kesehatan → selesai.
- Donor tidak layak: nomor donor tidak diterbitkan; peserta menuju pemeriksaan kesehatan hanya jika layanan tersebut dipilih.

Nomor donor baru dibuat setelah hasil kelayakan adalah `eligible`. Mode nomor donor dikonfigurasi per event: satu antrean global atau antrean terpisah laki-laki/perempuan. Nomor aktif yang dilepas karena pembatalan dapat digunakan kembali; histori nomor lama tetap disimpan.

## Fitur utama

- Authentication dan role-based navigation untuk panitia.
- CRUD Event, pengaturan nomor, dan satu event aktif.
- Master peserta, pencarian, dan autocomplete.
- Registrasi cepat dari drawer tanpa meninggalkan meja registrasi; hanya nama yang wajib dan nomor HP dapat dikosongkan.
- Live search peserta dengan debounce 300 ms, urutan relevansi, navigasi keyboard, dan maksimal 10 hasil.
- Konfigurasi Pos Pelayanan berdasarkan `behavior`, bukan nama/kode pos.
- Registrasi ulang dengan pilihan layanan.
- Pemeriksaan kelayakan, antrean donor, dan pemeriksaan kesehatan.
- Nomor registrasi global serta nomor donor global/terpisah gender.
- Card antrean responsif dan berkode warna gender pada seluruh meja operasional.
- Dashboard, antrean petugas, TV Monitor informatif, dan laporan realtime tanpa polling.
- Export Excel dan PDF.
- Audit log, policy, Form Request, transaksi, row lock, dan constraint database.
- Backup terjadwal, readiness endpoint, rate limiting, queue worker, dan Reverb.

## UX operasional

Meja registrasi dirancang untuk alur berulang dengan keyboard. Fokus awal berada pada kolom pencarian; setelah check-in berhasil form di-reset dan fokus kembali ke pencarian. Peserta yang tidak ditemukan dapat dibuat melalui drawer mobile dengan nama terisi dari kata pencarian, lalu otomatis dipilih pada form check-in.

Pemeriksaan kesehatan adalah konfirmasi layanan, bukan rekam medis. Petugas hanya memanggil, memulai, lalu menekan **Selesaikan Pemeriksaan**. Kolom medis lama tetap nullable di database untuk kompatibilitas histori dan API, tetapi tidak ditampilkan atau diwajibkan pada UI operasional.

TV Monitor menampilkan nomor, nama, layanan, status, dan instruksi pos tujuan. Semua meja, dashboard, serta monitor menerima pembaruan melalui Echo/Reverb tanpa polling atau reload halaman.

## Teknologi

- PHP 8.3+
- Laravel 12
- MySQL 8+ dengan InnoDB
- Redis untuk queue, cache, session, dan opsi scaling Reverb
- Laravel Reverb dan Laravel Echo
- Vite, Tailwind CSS, DaisyUI, dan Alpine.js
- Spatie Laravel Permission dan Spatie Laravel Backup
- PHPUnit, Laravel Pint, Larastan/PHPStan, dan ESLint

## Menjalankan secara lokal

Pastikan PHP 8.3 dari Laravel Herd, Composer, Node.js, npm, serta MySQL tersedia.

```powershell
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
```

Sesuaikan `.env` untuk lokal. Contoh minimum:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sehat_app
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb

REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=127.0.0.1

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Lanjutkan instalasi:

```powershell
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
npm run build
```

`SEED_ADMIN_EMAIL` harus valid. Pada instalasi baru, `SEED_ADMIN_PASSWORD` wajib diisi, minimal 12 karakter, dan bukan kata sandi umum. Menjalankan ulang seeder hanya menyinkronkan role/permission dan tidak mengubah password administrator yang sudah ada.

Untuk development, jalankan seluruh proses melalui:

```powershell
composer dev
```

Atau jalankan proses berikut pada terminal terpisah:

```powershell
php artisan queue:work --queue=broadcasts,default --tries=3 --backoff=2 --timeout=120
php artisan reverb:start
npm run dev
```

Jika tidak memakai web server Herd untuk project ini, jalankan juga `php artisan serve`.

## Quality gates

```powershell
php artisan test
vendor\bin\pint.bat --test
vendor\bin\phpstan.bat analyse --memory-limit=1G
npm run lint
npm run build
composer validate --strict
composer audit --locked --abandoned=report
npm audit --audit-level=high
```

## Dokumentasi

- [Workflow bisnis dan state](docs/business-workflow.md)
- [ERD final](docs/erd.md)
- [Architecture diagram](docs/architecture.md)
- [Database documentation](docs/database.md)
- [Production deployment guide](docs/production-deployment.md)
- [Final Master Refactor Report](docs/final-master-refactor-report.md)
- [Catatan kompatibilitas dokumen workflow lama](docs/dynamic-event-workflow.md)

Untuk production, jangan gunakan konfigurasi lokal di atas. Gunakan Redis, HTTPS/WSS, storage backup durable, process supervisor, dan checklist pada [Production Deployment Guide](docs/production-deployment.md).
