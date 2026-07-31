# Slice 4 — Master Peserta

## Fitur selesai

- CRUD master peserta: nama, nomor HP, NIK opsional, jenis kelamin, tanggal lahir, dan alamat.
- Normalisasi nama, NIK, dan nomor HP sebelum validasi dan penyimpanan.
- Pencarian JSON berdasarkan nama, nomor HP, atau NIK.
- Autocomplete tanpa reload halaman, mulai dari dua karakter.
- Policy berbasis `participants.manage`, audit log, transaction, dan broadcast Reverb `participant.updated`.
- Daftar peserta di Alpine diperbarui ketika menerima broadcast, tanpa polling.

## Keputusan desain

Peserta adalah master identitas global sehingga seseorang tidak diduplikasi untuk kegiatan berikutnya. Keterikatan ke event sengaja ditunda ke `event_participants` pada Slice Registrasi Ulang; desain ini mempertahankan normalisasi sambil memastikan data operasional tetap event-scoped.

NIK bersifat nullable-unique, sedangkan nomor HP diindeks karena merupakan kunci pencarian lapangan yang paling cepat. Daftar dibatasi 100 hasil agar respons tetap cepat; autocomplete dan kata kunci spesifik digunakan untuk data yang lebih besar.

## Struktur utama

```text
app/
├── Enums/ParticipantGender.php
├── Events/ParticipantUpdated.php
├── Http/Controllers/Participant/
├── Http/Requests/Participant/
├── Http/Resources/ParticipantResource.php
├── Models/Participant.php
├── Policies/ParticipantPolicy.php
└── Services/Participant/ParticipantService.php

database/migrations/
└── 2026_07_29_000009_create_participants_table.php

resources/views/participants/
├── index.blade.php
├── create.blade.php
└── edit.blade.php
```

## Pengujian

```powershell
php artisan migrate
php artisan test --filter=ParticipantManagementTest
npm run build
```
