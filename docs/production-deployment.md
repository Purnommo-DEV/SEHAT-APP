# Production Deployment Guide

Panduan ini adalah checklist deployment production SEHAT-APP. Contoh path Linux menggunakan `/var/www/sehat-app`; sesuaikan dengan release strategy server.

## 1. Prasyarat

- PHP 8.3+ beserta extension Laravel, PDO MySQL, Redis, mbstring, XML, cURL, ZIP, dan GD.
- Composer 2.
- MySQL 8+ dengan InnoDB.
- Redis untuk queue, cache, session, scheduler lock, dan opsional scaling Reverb.
- Node.js/npm untuk build asset.
- Nginx/Apache atau load balancer yang mendukung WebSocket upgrade.
- Supervisor, systemd, atau orchestrator container.
- Storage backup durable, misalnya S3.
- DNS dan sertifikat TLS untuk aplikasi serta endpoint WebSocket.

Verifikasi runtime:

```bash
php -v
composer --version
node -v
npm -v
mysql --version
redis-cli ping
```

## 2. Environment

Salin `.env.example` ke `.env`, lalu isi semua secret. Baseline penting:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sehat.example.com
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sehat_app
DB_USERNAME=sehat_app
DB_PASSWORD=strong-secret

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sehat-production
REVERB_APP_KEY=strong-random-key
REVERB_APP_SECRET=strong-random-secret

# Endpoint publik yang dipakai broadcaster dan browser.
REVERB_HOST=ws.sehat.example.com
REVERB_PORT=443
REVERB_SCHEME=https

# Bind internal proses Reverb.
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Hostname origin aplikasi, dipisahkan koma; jangan tulis URL/path.
REVERB_ALLOWED_ORIGINS=sehat.example.com

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

BACKUP_DISK=s3
BACKUP_ARCHIVE_PASSWORD=strong-backup-secret
BACKUP_VERIFY=true
```

Perbedaan yang wajib dipahami:

- `REVERB_HOST`, `REVERB_PORT`, dan `REVERB_SCHEME` adalah endpoint publik.
- `REVERB_SERVER_HOST` dan `REVERB_SERVER_PORT` adalah alamat bind proses lokal.
- Variable `VITE_REVERB_*` dibaca saat build. Perubahan endpoint memerlukan `npm run build` ulang.
- HTTPS aplikasi harus memakai `wss`; Echo sengaja hanya mengaktifkan transport yang sesuai scheme.
- `REVERB_ALLOWED_ORIGINS` dibandingkan sebagai hostname, bukan URL lengkap.

Jangan menggunakan `*` untuk allowed origin production.

## 3. Build release

```bash
cd /var/www/sehat-app
composer install --no-dev --classmap-authoritative --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Khusus instalasi baru, buat key satu kali sebelum migration:

```bash
php artisan key:generate
```

Pada deployment berikutnya, pertahankan `APP_KEY` yang sama agar session dan data terenkripsi tetap dapat dibaca.

Pastikan web user dapat menulis:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Seeder role/permission hanya dijalankan saat instalasi awal atau ketika memang ingin menyinkronkan role:

```bash
php artisan db:seed --class=RolePermissionSeeder --force
```

Isi `SEED_ADMIN_EMAIL` yang valid. Pada instalasi baru, isi `SEED_ADMIN_PASSWORD` yang kuat sebelum menjalankan seeder. Rerun pada instalasi yang sudah memiliki administrator menyinkronkan role/permission tanpa merotasi password; rotasi password harus dilakukan melalui prosedur kredensial tersendiri.

## 4. Migration SOP final

Lima migration penting:

- `2026_07_30_000024_replace_legacy_workflow_with_participant_services.php`
- `2026_07_30_000025_finalize_sop_numbering_and_timestamps.php`
- `2026_07_30_000026_reconcile_legacy_health_timestamps.php`
- `2026_07_30_000027_reconcile_legacy_donor_eligibility_timestamps.php`
- `2026_07_30_000028_make_participant_phone_optional.php`

Sebelum production:

1. Buat snapshot database.
2. Restore snapshot ke staging.
3. Jalankan seluruh migration.
4. Verifikasi backfill `event_participant_services`, nomor registrasi aktif, scope nomor tiket, mode donor event lama, timestamp, dan nullable phone peserta.
5. Jalankan test workflow terhadap data staging.

Migration mempertahankan histori, melakukan normalisasi nomor aktif sebelum memasang unique constraint, serta merekonsiliasi timestamp kesehatan dan kelayakan donor lama. Rollback `000025` juga menormalkan nomor historis yang sudah dipakai ulang sebelum unique index lama dipasang kembali. Walaupun reversible, rollback tetap wajib diuji pada salinan database dan didahului backup.

## 5. Reverse proxy Reverb

Contoh Nginx untuk subdomain WebSocket:

```nginx
server {
    listen 443 ssl http2;
    server_name ws.sehat.example.com;

    ssl_certificate /etc/letsencrypt/live/ws.sehat.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ws.sehat.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
        proxy_buffering off;
    }
}
```

Batasi port `8080` agar tidak terekspos langsung ke internet. TLS dihentikan di reverse proxy; proses Reverb tetap bind ke port internal.

## 6. Background process

Template Supervisor tersedia di `deployment/supervisor/`:

- `sehat-app-worker.conf`
- `sehat-app-reverb.conf`
- `sehat-app-scheduler.conf`

Pasang dan aktifkan:

```bash
cp deployment/supervisor/*.conf /etc/supervisor/conf.d/
supervisorctl reread
supervisorctl update
supervisorctl status
```

Worker menjalankan:

```text
queue:work redis --queue=broadcasts,default --tries=3 --backoff=3 --timeout=120
```

Queue `broadcasts` harus diproses lebih dahulu agar dashboard, antrean, dan TV Monitor tidak tertinggal. Event broadcast menggunakan `afterCommit=true`; worker tidak boleh dihilangkan walaupun Reverb hidup.

Reverb menjalankan:

```text
reverb:start --host=0.0.0.0 --port=8080
```

Scheduler menjalankan `schedule:work` dan mengatur:

- backup database pukul 01:00;
- cleanup backup pukul 02:00;
- monitor backup pukul 03:00;
- prune failed jobs dan batches;
- cleanup password reset token.

Setelah release:

```bash
php artisan queue:restart
supervisorctl restart sehat-app-worker:*
supervisorctl restart sehat-app-reverb
supervisorctl restart sehat-app-scheduler
```

Untuk multi-instance Reverb, aktifkan `REVERB_SCALING_ENABLED=true` dan pastikan seluruh instance memakai Redis serta app credentials yang sama.

## 7. Cache dan optimization

```bash
php artisan optimize:clear
php artisan optimize
php artisan event:cache
php artisan view:cache
```

Jalankan `optimize:clear` ketika troubleshooting perubahan `.env`/route/config, kemudian bangun cache kembali. Restart worker dan Reverb setelah perubahan konfigurasi.

## 8. Readiness dan observability

Endpoint:

- `/up`: health Laravel dasar.
- `/ready`: memeriksa koneksi database dan cache; `200` bila siap, `503` bila dependency gagal.

Contoh:

```bash
curl --fail https://sehat.example.com/up
curl --fail https://sehat.example.com/ready
php artisan schedule:list
php artisan queue:failed
php artisan backup:monitor
```

Monitor minimal:

- HTTP error rate dan latency;
- `/ready`;
- worker uptime, queue depth, latency queue `broadcasts`, dan failed jobs;
- Reverb uptime, connection count, disconnect, serta error origin/auth;
- database/Redis availability;
- storage dan log growth;
- status serta umur backup;
- exception aplikasi dan audit anomaly.

Log Supervisor diarahkan ke:

- `storage/logs/worker.log`
- `storage/logs/reverb.log`
- `storage/logs/scheduler.log`

Gunakan log rotation pada level OS.

## 9. Verifikasi realtime

Verifikasi konfigurasi server:

```bash
php artisan about
php artisan config:show broadcasting
php artisan config:show reverb
php artisan route:list
php artisan channel:list
supervisorctl status
```

Smoke test dengan dua browser:

1. Login sebagai petugas registrasi pada browser A.
2. Buka dashboard atau TV Monitor pada browser B.
3. Pastikan DevTools Network menunjukkan koneksi `wss://ws.sehat.example.com/app/...` berstatus `101 Switching Protocols`.
4. Pastikan request `/broadcasting/auth` berhasil ketika subscribe private channel.
5. Registrasikan peserta layanan donor.
6. Pastikan antrean kelayakan, dashboard, dan TV Monitor berubah tanpa reload.
7. Nyatakan peserta layak; pastikan nomor donor baru muncul.
8. Selesaikan donor dan pemeriksaan kesehatan; pastikan seluruh layar dan report tersinkron.
9. Putuskan jaringan sementara lalu sambungkan kembali; client harus refresh snapshot setelah status Echo kembali `connected`.

Event canonical yang harus terlihat:

```text
participant.registered
participant.moved-to-eligibility
participant.eligible / participant.ineligible
participant.moved-to-donation
participant.donation-completed
participant.moved-to-health-check
participant.health-check-completed
queue.updated
dashboard.updated
tv-monitor.updated
```

## 10. Backup dan restore

Backup manual:

```bash
php artisan app:backup --only-db
php artisan backup:monitor
```

Production harus memakai storage durable di luar server aplikasi. Simpan password arsip di secret manager.

Uji restore berkala:

1. Ambil backup terakhir.
2. Restore ke database environment terpisah.
3. Jalankan `php artisan migrate:status`.
4. Verifikasi jumlah event, peserta, layanan, tiket, screening, dan pemeriksaan.
5. Jalankan smoke test laporan dan workflow read-only.
6. Catat RPO/RTO aktual.

Backup yang tidak pernah diuji restore belum dianggap valid.

## 11. Quality gate sebelum deploy

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run lint
npm run build
composer validate --strict
composer audit --locked --abandoned=report
npm audit --audit-level=high
php artisan migrate:status
```

Deployment dihentikan bila test, static analysis, build, migration, atau audit dependency gagal.

## 12. Checklist go-live

- [ ] Secret dan `APP_KEY` tersimpan di secret manager.
- [ ] `APP_DEBUG=false`, HTTPS, secure cookie, dan trusted proxy benar.
- [ ] Database production menggunakan InnoDB dan memiliki backup pre-migration.
- [ ] Redis tersedia untuk cache, session, queue, dan scheduler lock.
- [ ] Migration `000024` sampai `000028` tervalidasi pada staging.
- [ ] Asset dibangun menggunakan endpoint `VITE_REVERB_*` production.
- [ ] DNS/TLS endpoint WebSocket valid.
- [ ] Allowed origin hanya memuat hostname aplikasi.
- [ ] Worker `broadcasts,default`, Reverb, dan scheduler berstatus running.
- [ ] `/up` dan `/ready` berhasil.
- [ ] Queue gagal kosong atau sudah ditangani.
- [ ] Backup pertama sehat dan restore pernah diuji.
- [ ] Workflow donor-only, health-only, donor+health, layak, dan tidak layak lulus smoke test.
- [ ] Dashboard, seluruh antrean, TV Monitor, dan report berubah tanpa reload.
- [ ] Monitoring dan alert aktif.

## 13. Rollback release

Jika release aplikasi bermasalah:

1. Aktifkan maintenance mode bila diperlukan.
2. Alihkan symlink/current release ke versi aplikasi sebelumnya.
3. Pertahankan schema baru selama kode lama masih kompatibel.
4. Restart worker, Reverb, scheduler, dan PHP-FPM.
5. Bersihkan/bangun ulang cache.
6. Verifikasi `/ready` dan antrean.

Jika perubahan database harus dibatalkan, gunakan restore snapshot atau prosedur rollback yang sudah diuji di staging. Jangan menghapus nomor, tiket, atau histori peserta secara manual.
