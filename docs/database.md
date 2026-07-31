# Database Documentation

Schema production ditujukan untuk MySQL 8+ dengan InnoDB. PostgreSQL juga mendapat CHECK constraint yang sama dari migration; SQLite digunakan terutama untuk test. Seluruh perubahan schema harus melalui migration.

Lihat [ERD Final](erd.md) untuk relasi visual.

## Tabel domain

### `events`

Menyimpan event operasional.

Kolom penting:

- `code`: kode unik event.
- `status`: `draft`, `active`, `completed`, atau `cancelled`.
- `active_marker`: hanya bernilai `active` atau `NULL`; unique constraint memastikan maksimal satu event aktif.
- `starts_at`, `ends_at`, `ended_at`: lifecycle waktu event.
- `created_by`: pembuat event.

### `event_settings`

Relasi one-to-one dengan event dan menyimpan kontrak tampilan nomor:

- `registration_number_format`: `uniform` atau `gender_prefix`.
- `registration_queue_prefix`: prefix format uniform.
- `registration_male_prefix`, `registration_female_prefix`: prefix registrasi berdasarkan gender.
- `registration_queue_digits`: 1–6 digit.
- `donor_number_mode`: `global` atau `gender_separated`.
- `donor_queue_prefix`: prefix lane donor global.
- `donor_queue_digits`: 1–6 digit.
- `male_donor_queue_prefix`, `female_donor_queue_prefix`: prefix lane donor terpisah.
- `general_queue_digits`: kompatibilitas nomor antrean non-donor lama.

Default event baru adalah registrasi dengan prefix gender dan nomor donor global. Migration `000025` mempertahankan tampilan event lama: event yang sudah mempunyai lane donor laki-laki/perempuan ditandai `gender_separated`.

### `participants`

Master identitas global peserta:

- NIK nullable tetapi unik jika terisi.
- Nama dan nomor telepon terindeks untuk pencarian.
- Nomor telepon nullable agar peserta baru dapat dibuat cepat dari meja registrasi hanya dengan nama.
- Gender menggunakan enum `male` atau `female`.
- Tanggal lahir dan alamat bersifat opsional.

### `service_posts`

Konfigurasi meja pelayanan per event:

- `code` dan `sequence` unik di dalam event.
- `behavior` menentukan form dan peran workflow.
- `queue_prefix` serta `queue_number_digits` mengatur antrean non-donor.
- `is_active` menentukan ketersediaan pos.
- Composite unique `(event_id, id)` mendukung scoped foreign key.

Behavior yang tersedia:

- `screening_form`
- `donation_form`
- `health_form`
- `confirmation_only`
- `custom_form`

Kolom `type` dipertahankan untuk kompatibilitas data lama; routing baru tidak bergantung pada nama/kode pos atau `type`.

### `event_participants`

Mewakili kehadiran satu peserta pada satu event.

- Unique `(event_id, participant_id)` membuat check-in idempotent.
- `registration_number` menyimpan angka historis.
- `active_registration_number` berisi angka selama registrasi aktif, atau `NULL` setelah dibatalkan.
- Unique `(event_id, active_registration_number)` menjaga satu angka aktif per event.
- `current_service_post_id` adalah posisi antrean saat ini dan menggunakan scoped FK agar pos berasal dari event yang sama.
- `status` adalah ringkasan state operasional.
- `checked_in_at`, `completed_at`, dan `cancelled_at` mencatat lifecycle.
- `checked_in_by` serta `cancelled_by` mencatat petugas.

Status yang mungkin muncul:

```text
registered, checked_in, waiting_service, service_in_progress,
waiting_health, health_in_progress, waiting_screening, not_eligible,
waiting_donor, donation_in_progress, donation_completed,
health_check_completed, finished, cancelled
```

State generik dipertahankan untuk data legacy. Workflow SOP baru terutama memakai state spesifik layanan.

### `event_participant_services`

Sumber kebenaran layanan yang dipilih dan progres tiap layanan.

- Unique `(event_participant_id, service)`.
- Scoped FK `(event_id, event_participant_id)`.
- Index `(event_id, service, status)` untuk dashboard/report.
- `selected_at`: waktu layanan dipilih.
- `eligibility_started_at`, `eligibility_completed_at`: khusus kelayakan donor.
- `started_at`, `completed_at`: waktu layanan donor atau kesehatan.

Nilai `service` saat ini:

```text
donor, health_check
```

Nilai `status`:

```text
pending, waiting_screening, screening_in_progress,
waiting_donation, donation_in_progress,
waiting_health_check, health_check_in_progress,
completed, not_eligible, cancelled
```

### `queue_tickets`

Histori dan state antrean per Pos Pelayanan.

- Unique `(event_participant_id, service_post_id)` mencegah tiket ganda pada pos yang sama.
- Composite FK memastikan peserta dan pos berasal dari event tiket.
- `queue_type`: `general`, `donor_global`, `male_donor`, atau `female_donor`.
- `number`: nomor historis yang tidak dihapus ketika tiket dibatalkan.
- `number_scope`: scope alokasi, misalnya `post:12` atau `lane:donor_global`.
- `active_number`: sama dengan `number` selama tiket aktif dan `NULL` setelah dibatalkan.
- Unique `(event_id, number_scope, active_number)` menjaga nomor aktif dan memungkinkan reuse setelah pembatalan.
- `called_at`, `served_at`, `finished_at`, `cancelled_at`: lifecycle tiket.
- `called_by`, `cancelled_by`: petugas.

Status tiket:

```text
waiting, calling, serving, finished, skipped, cancelled
```

CHECK constraint memastikan nomor positif, `active_number` konsisten dengan `number`, dan urutan timestamp tidak mundur.

### `donor_screenings`

Hasil kelayakan donor per peserta dan pos:

- `result`: `eligible` atau `not_eligible`.
- `reason`: alasan opsional/wajib sesuai validasi hasil.
- `screened_by`: petugas screening.
- Unique `(event_participant_id, service_post_id)`.
- Index `(event_id, service_post_id, created_at)`.

`service_post_id` tetap nullable untuk kompatibilitas record lama yang belum dapat dipetakan.

### `health_assessments`

Record konfirmasi layanan kesehatan per peserta dan pos. Kolom medis legacy tetap tersedia dan nullable untuk kompatibilitas histori/API:

- tekanan darah;
- gula darah;
- kolesterol;
- asam urat;
- catatan;
- petugas pembuat.

Unique `(event_participant_id, service_post_id)` dan index `(event_id, service_post_id, created_at)` mendukung beberapa pos pemeriksaan dalam histori. Nilai numerik yang dikirim tetap dilindungi CHECK constraint, tetapi tidak ada nilai medis yang diwajibkan untuk menyelesaikan layanan.

### `service_post_submissions`

Data penyelesaian behavior generic:

- `payload` JSON;
- `completed_by`;
- `completed_at`;
- unique `(event_participant_id, service_post_id)`.

### `service_post_user`

Pivot assignment operator ke Pos Pelayanan. Primary key gabungan `(service_post_id, user_id)` mencegah assignment ganda.

### `audit_logs`

Append-only audit operasional:

- scope event dan user nullable;
- `action`;
- polymorphic `subject_type`/`subject_id`;
- snapshot JSON `old_values` dan `new_values`;
- `created_at`.

Foreign key ke event/user memakai `nullOnDelete`; subject polymorphic sengaja tidak memiliki FK fisik.

## Tabel keamanan dan infrastruktur

- `users`: akun, password hash, dan status aktif.
- Tabel Spatie Permission: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.
- Queue: `jobs`, `job_batches`, `failed_jobs`.
- Cache: `cache`, `cache_locks`.
- Session/reset token mengikuti migration Laravel foundation.

## Algoritma nomor terkecil yang tersedia

Registrasi dan antrean memakai algoritma yang sama:

1. Caller mengunci row event di dalam transaction.
2. Query dibatasi ke event dan scope nomor.
3. Hanya kolom nomor aktif yang dibaca.
4. Nomor diurutkan naik.
5. Generator berjalan dari kandidat `1` dan berhenti pada gap pertama.
6. Record disimpan dengan nomor historis dan nomor aktif.
7. Unique constraint memvalidasi hasil.

Contoh nomor aktif `1, 2, 4, 5` menghasilkan nomor baru `3`.

`AUTO_INCREMENT` hanya digunakan untuk primary key database, bukan sebagai nomor registrasi atau nomor antrean bisnis.

## Pembatalan dan reuse

Pembatalan tidak menghapus histori:

- `registration_number`/`queue_tickets.number` tetap terisi;
- `active_registration_number`/`queue_tickets.active_number` menjadi `NULL`;
- status menjadi `cancelled`;
- waktu serta petugas pembatalan disimpan.

Karena unique constraint hanya mengikat kolom nomor aktif, angka tersebut dapat dipakai record berikutnya.
Pembatalan registrasi hanya diizinkan sebelum pelayanan dimulai atau selesai. Setelah ada tiket
`serving`/`finished`, histori hasil layanan tidak dapat diturunkan menjadi `cancelled`.

## Migration compatibility

### `2026_07_30_000024_replace_legacy_workflow_with_participant_services.php`

- Menambahkan nomor registrasi.
- Membuat `event_participant_services`.
- Backfill layanan donor/kesehatan dari tiket, screening, pemeriksaan, current post, dan status lama.
- Menambah index serta CHECK constraint layanan.

### `2026_07_30_000025_finalize_sop_numbering_and_timestamps.php`

- Menambah format registrasi dan mode nomor donor.
- Menambah kolom nomor aktif agar reuse tidak menghapus histori.
- Menambah scope nomor antrean dan lane donor global.
- Menambah timestamp kelayakan, selesai, dan pembatalan.
- Memetakan lane lama ke mode donor yang kompatibel.
- Menormalkan duplikasi nomor lama sebelum memasang unique constraint final.
- Memperbarui CHECK constraint untuk enum/status baru.
- Menormalkan nomor historis secara deterministik bila migration di-rollback setelah reuse.

### `2026_07_30_000026_reconcile_legacy_health_timestamps.php`

- Merekonsiliasi waktu mulai kesehatan dari `queue_tickets.served_at`.
- Merekonsiliasi waktu selesai dari `queue_tickets.finished_at` atau waktu pembuatan assessment.
- Mempertahankan koreksi timestamp saat rollback karena koreksi histori tidak merusak schema.

### `2026_07_30_000027_reconcile_legacy_donor_eligibility_timestamps.php`

- Merekonsiliasi waktu mulai dan selesai pemeriksaan kelayakan donor.
- Menggunakan waktu keputusan screening sebagai fallback konservatif bila data legacy tidak memiliki tiket screening.
- Mempertahankan koreksi timestamp saat rollback.

### `2026_07_30_000028_make_participant_phone_optional.php`

- Mengubah `participants.phone` menjadi nullable untuk registrasi peserta cepat.
- Tidak menghapus index pencarian nomor telepon.
- Rollback mengembalikan kontrak non-null setelah menormalisasi nilai `NULL`.

Selalu uji migration `000024` sampai `000028` pada salinan database production sebelum deploy. Jangan mengedit migration yang sudah pernah dijalankan; buat migration baru untuk perubahan berikutnya.

## Integritas dan locking

- Mutation antrean berjalan dalam transaction.
- Event, peserta, tiket, pos, dan setting yang relevan dikunci menggunakan `lockForUpdate`.
- Scoped foreign key mencegah IDOR/cross-event data corruption pada lapisan database.
- Unique serta CHECK constraint tetap aktif walaupun validasi aplikasi terlewati.
- Production wajib menggunakan storage engine transaksional InnoDB.

## Operasi database

```powershell
php artisan migrate:status
php artisan migrate --force
php artisan db:show
php artisan queue:failed
```

Backup database dijadwalkan setiap hari dan harus diarahkan ke disk durable. Prosedur deployment serta restore tersedia di [Production Deployment Guide](production-deployment.md).
