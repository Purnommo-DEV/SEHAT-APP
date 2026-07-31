# Catatan Kompatibilitas Workflow Lama

Nama file ini dipertahankan agar link dokumentasi lama tidak rusak. Dokumen ini bukan lagi sumber kebenaran workflow.

Gunakan dokumen final berikut:

- [Final Business Workflow](business-workflow.md)
- [ERD Final](erd.md)
- [Architecture Diagram](architecture.md)
- [Database Documentation](database.md)

## Perubahan dari arsitektur linear

Implementasi lama memindahkan peserta ke Pos Pelayanan aktif berikutnya berdasarkan `sequence`. Aturan tersebut telah digantikan untuk peserta yang mempunyai `event_participant_services`.

Workflow aktif sekarang:

- membaca layanan yang dipilih peserta;
- memilih titik masuk berdasarkan metadata `ParticipantServiceType`;
- mencari pos aktif berdasarkan `ServicePostBehavior`;
- membuat nomor donor hanya setelah peserta dinyatakan layak;
- meneruskan peserta ke pemeriksaan kesehatan hanya jika layanan tersebut dipilih;
- menyimpan state donor dan kesehatan secara terpisah.

Nama, kode, dan `type` legacy sebuah pos tidak menentukan cabang bisnis.

`sequence` masih digunakan untuk:

- mengurutkan tampilan Pos Pelayanan;
- memilih pos pertama apabila event memiliki lebih dari satu pos aktif dengan behavior sama;
- menjalankan fallback linear khusus record legacy yang belum mempunyai pilihan layanan.

Fallback legacy tidak boleh digunakan sebagai dasar fitur baru.

## Kompatibilitas data

Migration `2026_07_29_000020_refactor_dynamic_event_workflow.php` tetap relevan untuk menambahkan behavior, assignment operator, serta submission per pos.

Migration berikutnya menyelesaikan peralihan ke SOP final:

- `2026_07_30_000024_replace_legacy_workflow_with_participant_services.php`
- `2026_07_30_000025_finalize_sop_numbering_and_timestamps.php`
- `2026_07_30_000026_reconcile_legacy_health_timestamps.php`
- `2026_07_30_000027_reconcile_legacy_donor_eligibility_timestamps.php`

Keempatnya mempertahankan record event, peserta, tiket, screening, dan pemeriksaan lama sambil melakukan backfill layanan, nomor, lane, serta timestamp yang dapat dipetakan.

## Behavior Pos Pelayanan

| Behavior | Fungsi saat ini |
| --- | --- |
| `screening_form` | Pemeriksaan kelayakan donor |
| `donation_form` | Pelayanan donor |
| `health_form` | Pemeriksaan kesehatan |
| `confirmation_only` | Completion generic/kompatibilitas |
| `custom_form` | Catatan pelayanan tambahan/kompatibilitas |

Event baru harus memiliki pos aktif untuk screening, donor, dan pemeriksaan kesehatan sebelum dapat diaktifkan.
