# Slice 1 — Project Foundation

Slice ini menyediakan fondasi yang dapat dijalankan untuk seluruh fitur bisnis berikutnya.

## Cakupan

- Login berbasis session tanpa registrasi publik.
- Proteksi brute force dan penolakan akun nonaktif.
- Role serta permission menggunakan Spatie Laravel Permission.
- Role awal: Administrator, Panitia Registrasi, Panitia Kesehatan, Panitia Screening, Panitia Donor, dan Viewer.
- Layout Blade responsif dengan TailwindCSS, DaisyUI, AlpineJS, dan ikon Heroicons inline.
- Dashboard foundation yang dilindungi permission.
- Laravel Reverb dan Echo, termasuk indikator status koneksi pada UI.

## Menjalankan aplikasi

Pastikan Laravel Herd menggunakan PHP 8.3:

```powershell
herd use 8.3
php -v
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

Untuk pengembangan, jalankan proses berikut pada terminal terpisah:

```powershell
php artisan serve
php artisan queue:listen
php artisan reverb:start
npm run dev
```

Sesuaikan `SEED_ADMIN_EMAIL` dan `SEED_ADMIN_PASSWORD` sebelum menjalankan seeder pada lingkungan non-lokal.

## Pengujian

```powershell
php artisan test
```

Test mencakup login, normalisasi email, akun nonaktif, logout, authorization dashboard, seeder role/permission, dan konfigurasi Reverb.
