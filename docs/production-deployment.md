# Production Deployment Guide

## Prerequisites

- PHP 8.3+, Composer, Node 20+, MySQL 8+ with InnoDB.
- HTTPS, writable `storage` and `bootstrap/cache`, configured durable backups and monitoring.
- A secure `APP_KEY`, `APP_DEBUG=false`, trusted `APP_URL`, and explicit database/cache/session credentials.

## Deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan storage:link
php artisan optimize
```

Restart the queue worker after every deployment. Run `php artisan schedule:work` or configure cron for `schedule:run` each minute. Verify `/ready`, logs, backup target, disk capacity, and queue failures.

## Realtime mode

For VPS: run a supervised `reverb:start` process and `queue:work --queue=broadcasts,default`. Terminate TLS at the proxy and forward WebSocket upgrade headers. Use one consistent public hostname for `APP_URL`, Reverb, and Vite variables.

For shared hosting: set `REALTIME_DRIVER=polling` and `REALTIME_POLLING_INTERVAL_MS=5000`. Reverb does not need to run; pages update data without a browser reload.

## Smoke checklist

- [ ] Run migrations and confirm `event_participant_status_histories`, `event_settings.donation_capacity_male`, `event_settings.donation_capacity_female`, and `event_donation_capacity_lanes` exist.
- [ ] Verify panitia can open Registrasi, Menunggu, Screen Petugas, Cek Kesehatan, Sedang Donor, Selesai, Dashboard, and TV Monitor without login, only for the active event.
- [ ] Verify Event, peserta administratif, pos pelayanan, laporan, konfigurasi, dan user/role management still redirect guests to login and enforce permission.
- [ ] Create/activate event with active Health and Donation posts.
- [ ] Register a participant and process Menunggu → Cek Kesehatan → Sedang Donor → Selesai.
- [ ] Confirm audit rows, status history, Dashboard, TV Monitor, and each separate area screen update.
- [ ] Verify Next, Skip, and Goto work only from Screen Petugas Area Tunggu for each gender lane, including an invalid Goto message.
- [ ] Test separate male/female donor mode (`L001` and `P001`) and global mode (`D001`).
- [ ] Set male capacity to 4 and female capacity to 4; verify 4/4 male does not block female donor, 4/4 female does not block male donor, and only the full gender remains in Cek Kesehatan.
- [ ] Verify only the administrator or a user granted `event.update_donation_capacity` can change active-event capacity; lowering below active donors must be rejected and must leave an audit row.
- [ ] Validate `/ready`, worker, Reverb or polling, backups, and error monitoring.
