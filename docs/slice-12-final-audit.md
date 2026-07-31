# Slice 12 — Final QA, Audit, Hardening, dan Production Readiness

> Snapshot audit historis sebelum Final Master Refactor. Angka test, migration, dan klaim kelulusan di bawah hanya berlaku pada tanggal audit tersebut. Gunakan [Production Deployment Guide](production-deployment.md) dan jalankan quality gate aktual sebelum deploy.

Tanggal audit: 29 Juli 2026

## Status

Slice 12 selesai. Audit tidak menemukan masalah kritis atau mayor yang tersisa pada kode aplikasi, migration, route, UI utama, dan quality gates lokal.

## Temuan dan perbaikan

### Database

- Composite foreign key ditambahkan untuk memastikan `event_id` konsisten dengan peserta dan pos layanan pada tiket antrean, pemeriksaan kesehatan, dan screening.
- Check constraint ditambahkan untuk database yang mendukungnya; SQLite tetap dilindungi oleh enum dan Form Request.
- Index redundant dihapus dan index scoped `(event_id, type, is_active)` ditambahkan.
- Migration integrity diuji dengan PRAGMA SQLite dan skenario cross-event yang harus gagal.

### Arsitektur dan maintainability

- PHPStan/Larastan level 7 ditambahkan dan seluruh `app`, `database`, serta `routes` lulus analisis.
- Model relationship diberi PHPDoc generik sehingga kontrak query lebih jelas.
- Dashboard diubah dari pemuatan seluruh peserta menjadi aggregate query dan maksimum 30 posisi dengan eager loading.
- Event realtime memakai queued broadcast after-commit pada queue `broadcasts`; subscription Echo lama dilepas saat event berganti.
- Class, route, dan view yang tidak digunakan dihapus atau dikonfirmasi sebagai bagian dari auto-discovery/permission contract.

### Security

- Security headers, CSP baseline, HSTS production, Referrer-Policy, Permissions-Policy, dan X-Frame-Options ditambahkan.
- Route operasional, laporan, dan readiness memakai rate limiter terpisah.
- Mass assignment, policy, validation, escaping Blade, CSRF, dan route model binding diaudit.
- Default password seeder dihapus; password admin seed wajib kuat dan email wajib valid.
- `/ready` hanya mengembalikan status dependency tanpa detail exception.

### Production operations

- `.env.example` dilengkapi konfigurasi production, Redis, Reverb HTTPS, backup, log retention, dan cookie aman.
- Scheduler mencakup backup database, backup clean/monitor, queue cleanup, dan reset token.
- Supervisor template tersedia untuk worker, Reverb, dan scheduler.
- Backup SQLite lokal memiliki fallback native `VACUUM INTO`; MySQL/PostgreSQL tetap memakai Spatie database dumper.
- Readiness, backup command, schedule, header, rate-limit, XSS, migration integrity, workflow, realtime, dan performa dashboard memiliki test feature.

## Quality gates

Perintah berikut dijalankan pada environment Laravel Herd:

```text
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse app database routes --no-progress --error-format=table --memory-limit=1G
npm run lint
npm run build
composer validate --strict
composer audit --locked --abandoned=report
npm audit --audit-level=high
php artisan migrate:status
php artisan schedule:list
php artisan backup:monitor
```

Hasil akhir:

- PHPUnit: **84 test lulus, 453 assertion**.
- Pint: lulus tanpa pelanggaran format.
- PHPStan/Larastan level 7: lulus tanpa error.
- ESLint: lulus tanpa error.
- Vite production build: berhasil.
- Composer audit: tidak ada advisory keamanan.
- npm audit: 0 vulnerability.
- Seluruh 20 migration berstatus `Ran`.
- Backup lokal berstatus sehat, `/ready` merespons HTTP 200, dan Reverb berhasil melakukan startup.

## Risiko yang masih tersisa

Tidak ada blocker aplikasi. Risiko yang tersisa bersifat deployment/infrastruktur:

1. Operator production harus mengisi secret, database, Redis, mail, Reverb, dan disk backup eksternal.
2. Backup production harus diarahkan ke S3 atau storage durable dan diuji restore berkala.
3. Worker, Reverb, dan scheduler harus dijalankan oleh Supervisor/systemd/container orchestrator.
4. Endpoint `/ready` perlu dipasang sebagai health probe internal dan tidak diekspos tanpa kontrol jaringan.
5. Persentase line coverage belum dapat dihitung pada environment Herd ini karena Xdebug/PCOV tidak tersedia; regression test feature/unit tetap dijalankan penuh.

## Checklist deployment production

- [ ] Salin `.env.example` menjadi `.env`, isi seluruh secret, lalu jalankan `php artisan key:generate`.
- [ ] Pastikan `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, trusted proxy, dan cookie secure.
- [ ] Konfigurasi MySQL/PostgreSQL, Redis, queue worker, cache, dan session.
- [ ] Jalankan `composer install --no-dev --optimize-autoloader` dan `npm ci && npm run build`.
- [ ] Jalankan `php artisan migrate --force`, `php artisan storage:link`, `php artisan optimize`.
- [ ] Konfigurasi Supervisor dari `deployment/supervisor/`.
- [ ] Verifikasi `/ready`, `php artisan schedule:list`, Reverb websocket, dan log.
- [ ] Jalankan backup pertama, `php artisan backup:monitor`, dan uji restore.
- [ ] Aktifkan monitoring error, queue failed jobs, disk, database, Redis, Reverb, dan backup.

Panduan command dan contoh Supervisor tersedia di [production deployment guide](production-deployment.md).
