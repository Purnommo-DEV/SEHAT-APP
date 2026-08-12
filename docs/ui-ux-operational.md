# UI/UX Operasional

Dokumen ini mencatat kontrak pengalaman pengguna pada meja panitia. Workflow domain tetap mengikuti `business-workflow.md`.

## Registrasi cepat

- Fokus awal dan fokus setelah check-in berada pada kolom pencarian peserta.
- Pencarian berjalan setelah minimal dua karakter dengan debounce 300 ms.
- Request pencarian sebelumnya dibatalkan ketika operator mengetik lagi.
- Hasil diurutkan berdasarkan kecocokan nama/nomor HP dan dibatasi 10 peserta.
- Keyboard panah memilih kandidat; Enter mengonfirmasi kandidat aktif.
- Empty state menyediakan aksi **Tambah Peserta Baru** tanpa navigasi.
- Drawer peserta cepat mewajibkan nama, menerima nomor HP opsional, dan menyediakan pemilihan gender satu ketuk untuk menjaga format nomor serta warna antrean.
- Setelah peserta tersimpan, drawer tertutup dan peserta otomatis dipilih.
- Setelah check-in berhasil, form dibersihkan dan fokus kembali ke pencarian.

## Tampilan responsif

- Layout bersifat mobile-first dengan card dan grid yang menyusut tanpa horizontal overflow.
- Menu panitia memuat Registrasi, Menunggu, Cek Kesehatan, Sedang Donor, Selesai, Screen Petugas, Dashboard, dan TV Monitor.
- Screen Petugas memuat kontrol Next/Skip/Goto per jalur laki-laki dan perempuan serta daftar posisi peserta aktif agar perpindahan tahap tidak terlihat sebagai peserta yang hilang.
- Menunggu hanya menampilkan peserta `waiting` dengan aksi **Cek Kesehatan**. Cek Kesehatan hanya menampilkan peserta `health_check` dengan aksi **Donor** atau **Selesai**. Sedang Donor hanya menampilkan peserta `donating` dengan aksi **Selesai** dan Area Selesai read-only.
- Next, Skip, dan Goto memiliki target sentuh minimal 48 px; Goto memakai modal nomor yang memvalidasi jalur gender dan ticket aktif tanpa membuat nomor baru. Next hanya memilih ticket berstatus Menunggu; ticket yang di-Skip tetap berada pada status peserta Menunggu dan dipanggil kembali melalui Goto.
- Drawer memenuhi sisi bawah layar ponsel dan berubah menjadi modal terpusat pada layar lebih besar.
- Input memakai ukuran font aman untuk mencegah zoom otomatis iPhone.
- Aksi check-in utama tetap terjangkau di bagian bawah layar kecil.
- Tabel yang tidak dapat diubah menjadi card tetap berada di container `overflow-x-auto`.
- Sidebar dibatasi tinggi viewport dan area konten memakai `min-width: 0`.

## Identitas visual antrean

Peserta laki-laki memakai palet biru/indigo/slate; peserta perempuan memakai palet pink/rose/purple. Badge gender dan warna card diterapkan melalui store UI Alpine yang sama pada registrasi, screening, donor, kesehatan, dashboard, dan TV Monitor agar tidak terjadi duplikasi aturan CSS.

## TV Monitor

Snapshot TV memuat nomor registrasi, nama peserta, dan gender yang sedang dipanggil. Nomor dan nama menggunakan tipografi besar; antrean berikutnya tetap ringkas dan responsif. Peserta yang telah masuk Cek Kesehatan atau Sedang Donor tetap tampil pada daftar posisi proses per gender sampai selesai.

## Realtime dan micro UX

- Echo/Reverb menyinkronkan snapshot setiap area kerja, Dashboard, dan TV Monitor; mode polling menjadi fallback jika `REALTIME_DRIVER=polling`. Tidak ada `location.reload()`.
- Tombol submit dinonaktifkan selama request dan menampilkan spinner.
- Toast singkat memberi hasil operasi.
- Empty state menjelaskan tindakan berikutnya.
- Refresh snapshot yang datang berdekatan tetap dikoaleskan untuk menghindari request berulang.
