# SEHAT-APP

SEHAT-APP adalah sistem operasional panitia donor darah dan pemeriksaan kesehatan berbasis Laravel 12. Workflow aktif mengikuti SOP UAT final:

```text
MENUNGGU → SEDANG DIPANGGIL → CEK KESEHATAN / SEDANG DONOR → SELESAI
```

Peserta memilih layanan Donor Darah, Pemeriksaan Kesehatan, atau keduanya saat registrasi. Layanan yang dipilih disimpan terpisah dari status operasional. Peserta kesehatan-saja diselesaikan dari tahap Cek Kesehatan tanpa masuk donor.

## Fitur operasional

- Registrasi cepat dengan nomor registrasi global yang memakai angka kosong terkecil.
- Satu **Screen Petugas** menampilkan antrean berikutnya, peserta sedang dipanggil, Cek Kesehatan, Sedang Donor, dan Selesai. Peserta tidak hilang dari pemantauan sebelum selesai.
- Next dan Goto mengubah status menjadi **Sedang Dipanggil**; Skip mengembalikannya ke antrean. Peserta yang sudah di Cek Kesehatan atau Donor tidak menghalangi pemanggilan berikutnya.
- Nomor donor selalu sama dengan nomor registrasi (`L021` tetap `L021`, `P021` tetap `P021`); tidak ada nomor donor kedua seperti `D001`.
- Setiap event memiliki kapasitas bed donor **per gender** (default **4** laki-laki dan **4** perempuan). Laki-laki 4/4 tidak membatasi perempuan; hanya lane yang penuh tetap di Cek Kesehatan sampai donor pada gender yang sama selesai.
- Administrator, atau petugas dengan permission `event.update_donation_capacity`, dapat memperbarui dua kapasitas saat event aktif. Perubahan diaudit dan tersinkron ke seluruh layar tanpa reload.
- Administrator dengan permission `event.reset_queue` dapat memakai **Danger Zone** pada Pengaturan Event untuk mengosongkan data operasional satu event. Master peserta, konfigurasi nomor, pos, kapasitas, dan jejak audit tetap tersimpan; registrasi berikutnya kembali memakai nomor awal yang dikonfigurasi.
- Transaction, row lock, validasi transisi, status history, dan audit log pada setiap aksi perubahan status.
- Dashboard read-only, TV Monitor read-only, dan laporan.
- Laravel Reverb + Echo untuk realtime; polling ringan tanpa reload bila `REALTIME_DRIVER=polling`.

## Akses

Panitia operasional tidak memakai login. Menu panitia terdiri dari Registrasi, Operasional, Dashboard, dan TV Monitor untuk event yang sedang aktif. Next, Skip, Goto, Cek Kesehatan, Donor, dan Selesai berada pada Screen Petugas yang sama. Seluruh mutation tetap melalui middleware `web` (CSRF), rate limit, validasi Form Request, route model binding berscope event, transaction, dan row lock.

Area manajemen tetap membutuhkan autentikasi dan permission: Event, master peserta, pos pelayanan, laporan, konfigurasi, serta administrasi pengguna/role.

## Menjalankan lokal

Gunakan PHP 8.3 Herd secara eksplisit bila PATH terminal masih menunjuk versi lain:

```powershell
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan migrate
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan db:seed --class=RolePermissionSeeder
npm install
npm run dev
```

Untuk realtime lokal, jalankan pada terminal terpisah:

```powershell
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan queue:work --queue=broadcasts,default --tries=3 --backoff=2 --timeout=120
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan reverb:start
```

Set `.env` dengan `REALTIME_DRIVER=reverb` untuk Reverb, atau `REALTIME_DRIVER=polling` pada shared hosting. Lihat [Realtime Guide](docs/realtime.md) dan [Polling Fallback Guide](docs/polling-fallback.md).

## Quality gate

```powershell
C:\Users\Kelascom\.config\herd\bin\php83\php.exe artisan test
vendor\bin\pint.bat --test
vendor\bin\phpstan.bat analyse --memory-limit=1G
npm run lint
npm run build
composer validate --strict
composer audit --locked --abandoned=report
npm audit --audit-level=high
```

## Dokumentasi aktif

- [Workflow dan state](docs/business-workflow.md)
- [ERD](docs/erd.md)
- [Arsitektur](docs/architecture.md)
- [Database](docs/database.md)
- [Deployment](docs/production-deployment.md)
- [Realtime](docs/realtime.md)
- [Polling fallback](docs/polling-fallback.md)

Dokumen `slice-*`, `dynamic-event-workflow.md`, dan `final-master-refactor-report.md` adalah riwayat pengembangan, bukan definisi workflow aktif.
