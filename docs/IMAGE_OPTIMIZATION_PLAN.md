# Optimasi Gambar Upload — Media Library, Banners, Site Settings

> **Status: SUDAH DIIMPLEMENTASI** (21 Juli 2026), termasuk perluasan
> ke Banners & Site Settings (Logo/OG image) — lihat § 9. Production
> dikonfirmasi punya GD dengan WebP support aktif, jalur WebP-first
> dipakai penuh. Lihat manifest final di § 7 dan entri Update Log di
> `SITEMAP.md` (21 Jul 2026) untuk ringkasan implementasi.

Ditulis: 19 Juli 2026. Direvisi: 21 Juli 2026 (perubahan format
JPG/WebP, limit 3 MB, deteksi transparansi, update teks UI). Selesai
diimplementasikan: 21 Juli 2026. Diperluas ke Banners & Site Settings:
21 Juli 2026 (§ 9).

---

## 0. TEMUAN — GD dan Imagick TIDAK TERSEDIA di sandbox dev ini

Sudah dicek sebelumnya, temuan tidak berubah (dicek lagi memastikan
tidak ada perubahan sejak revisi 1):

```
PHP 8.2.31 (docker php8_apache, dev container)
gd_info()        → GD NOT AVAILABLE
imagewebp()      → tidak ada
imagecreatefromwebp() → tidak ada
php -m           → tidak ada modul "gd" atau "imagick" sama sekali
extension_dir    → tidak ada file gd.so / imagick.so di situ
```

Bukan cuma "dimatikan di php.ini" — dua-duanya **tidak ter-install
sama sekali** di environment yang saya akses. Saya tidak punya akses ke
server production, jadi **statusnya di sana belum bisa saya
konfirmasi**. Ini tetap pertanyaan pembuka yang perlu jawaban Anda —
lihat § 8.

Desain di bawah tetap **aman di kedua skenario**: kalau production
ternyata juga tidak punya GD/Imagick, seluruh pipeline ini otomatis
no-op — upload tetap jalan seperti sekarang (minus limit 3 MB yang baru,
itu validasi murni PHP, tidak butuh GD/Imagick sama sekali), tidak
pernah gagal cuma karena tidak bisa kompres.

---

## 1. Bagaimana upload gambar bekerja sekarang

Dicek di `cms-admin/pages/media-library.php` (baris ~95-215):

1. Validasi ekstensi (allowlist: jpg/jpeg/png/webp/gif/pdf).
2. Deteksi MIME sungguhan via `finfo` (baris 122-126) — map MIME →
   ekstensi kanonis.
3. **Limit ukuran (baris 128-133): 5 MB gambar, 10 MB PDF — kalau
   lebih, request DITOLAK** (`$ml_redirect(...)`, bukan diproses
   paksa). Pola reject ini sudah persis seperti yang Anda minta di
   poin 1 — cuma angkanya yang perlu diturunkan, lihat § 2.
4. Bikin folder `uploads/media/<tahun>/<bulan>/` + guard `index.php`.
5. Nama file aman: `<basename-slug>-<16 hex random>.<ext>`.
6. `move_uploaded_file()` — **tidak ada resize/kompresi apa pun**, file
   tersimpan persis seperti yang diupload.
7. Path, MIME, ukuran (KB), tipe disimpan ke `media_library`.

Ini penyebab file PNG 1.5-2.4MB bisa masuk — dan karena semua upload
gambar artikel lewat sini (TinyMCE picker cuma *browse*, tidak upload
sendiri — sudah dikonfirmasi baca `tinymce-media-picker.php`), fix di
titik ini menutup seluruh jalur upload gambar artikel.

---

## 2. Batas ukuran upload — turun ke 3 MB, reject (bukan auto-kompres)

Ubah baris 128-133:

- `$maxBytes` untuk gambar: `5 * 1024 * 1024` → **`3 * 1024 * 1024`**.
  PDF tetap 10 MB (tidak diminta berubah, dan PDF di luar scope
  kompresi gambar ini).
- Pesan error diperjelas, sesuai contoh Anda:
  > `File terlalu besar (4.2 MB). Maksimal 3 MB — coba kompres gambar dulu atau pilih file lain.`
  (ukuran aktual file dihitung dari `$fileBytes`, ditampilkan dalam MB
  2 desimal).
- **Ini validasi PALING AWAL**, jalan SEBELUM langkah optimasi apa pun
  di § 3 — jadi perilakunya tidak bergantung sama sekali pada
  ketersediaan GD/Imagick. Sesuai instruksi Anda: file di atas 3 MB
  ditolak mentah-mentah, admin yang harus kompres manual/pilih file
  lain, sistem tidak diam-diam memaksakan.

---

## 3. Pipeline optimasi untuk file yang LOLOS validasi 3 MB

File baru **`cms-admin/includes/image-optimizer.php`**:

```php
function cms_image_optimizer_capabilities(): array
// return ['gd' => bool, 'imagick' => bool, 'webp' => bool]

function cms_image_optimize(string $sourcePath, string $targetDir, string $baseFilename, int $maxWidth = 1200, int $quality = 80): array
// TIDAK PERNAH throw — gagal apa pun = "skip, pakai file asli apa adanya"
// return ['ok' => bool, 'output_path' => string, 'output_mime' => string, 'skipped_reason' => string]
```

Dipanggil di `media-library.php` SETELAH `move_uploaded_file()` sukses,
SEBELUM data ditulis ke DB. Logikanya:

### 3a. Tentukan apakah file ini "photo-type" atau "butuh transparansi"

Ini bagian baru yang menjawab instruksi Anda ("bukan logo/ikon/
screenshot yang butuh transparansi"). Masalahnya: sistem tidak bisa
tahu secara semantik apakah sebuah gambar "logo" atau "foto" — tapi ada
proxy teknis yang reliable:

- **JPEG** → JPEG tidak pernah punya alpha channel sama sekali → selalu
  "photo-type", langsung eligible diproses.
- **PNG** → PNG *bisa* punya alpha channel, tapi channel itu sering ada
  di file walau tidak benar-benar dipakai (semua piksel opaque). Jadi
  ceknya bukan "apakah PNG punya alpha channel" (hampir selalu ya),
  tapi **"apakah alpha channel itu BENAR-BENAR dipakai"** — scan piksel
  (atau pakai channel statistics kalau lewat Imagick) untuk cari
  minimal satu piksel dengan alpha < 255 (ada transparansi nyata).
  - Ketemu transparansi nyata → ini kemungkinan besar logo/ikon/
    screenshot UI → **format TIDAK diubah, tetap PNG** (skip konversi
    JPG/WebP). Resize tetap boleh jalan kalau dimensinya kelewat besar
    (lihat § 3c), tapi lossless — compression level PNG dinaikkan
    (`imagepng()` level 0-9, tidak menurunkan kualitas visual sama
    sekali, cuma re-encode lebih efisien).
  - Semua piksel opaque (tidak ada transparansi nyata dipakai) → ini
    "photo-type" walau formatnya PNG → **eligible dikonversi** sesuai
    § 3b.

  Catatan performa: scan piksel dilakukan pada gambar yang sudah
  dibatasi ukurannya oleh limit 3 MB, jadi resolusinya wajar — bukan
  operasi berat. Kalau mau lebih hemat, bisa disederhanakan jadi
  sampling (cek grid piksel, bukan tiap piksel) — detail implementasi,
  tidak mengubah keputusan desain.
- **GIF, WebP, PDF** → skip total, sama seperti revisi sebelumnya (GIF
  bisa animasi, WebP sudah optimal, PDF bukan gambar).

### 3b. Format output untuk file "photo-type" — JPG dengan upgrade otomatis ke WebP

Ini jawaban langsung untuk pertanyaan Anda "JPG saja vs WebP+fallback,
mana yang lebih masuk akal untuk effort vs benefit":

**Rekomendasi saya: satu file output, formatnya dipilih otomatis
berdasarkan kemampuan server — bukan dua file (WebP + JPG) sekaligus.**

- Kalau server bisa encode WebP (GD dengan WebP support, atau Imagick)
  → output **WebP**, quality 80. Ini dapat penghematan 25-35% lebih
  yang Anda sebut.
- Kalau tidak bisa (GD tanpa WebP support, atau cuma GD/Imagick versi
  lama) → output **JPG**, quality 80. Tetap jauh lebih baik dari PNG
  untuk jenis gambar ini.

**Kenapa bukan simpan dua file (WebP + JPG) sekaligus:** gambar artikel
disisipkan lewat TinyMCE yang cuma insert satu tag `<img src="...">` —
bukan markup `<picture><source type="image/webp">...</picture>` yang
bisa otomatis pilih format sesuai browser. Tanpa markup itu, file JPG
"cadangan" tidak pernah benar-benar diserve ke siapa pun — cuma nambah
langkah encode + storage 2x tanpa manfaat nyata. Jadi pendekatan
capability-based single-file ini **memberi hasil yang sama secara
efektif** (WebP dipakai kalau bisa, JPG kalau tidak bisa) **dengan
kompleksitas jauh lebih rendah** dari pendekatan dual-file — cuma satu
percabangan if/else di titik encode, bukan pipeline paralel dua format.
Ini yang saya maksud "effort rendah, benefit hampir sama" dari
pertanyaan Anda.

Kalau suatu saat Anda mau proper `<picture>` dual-serving (browser lama
dapat JPG, browser modern dapat WebP, otomatis lewat markup) — itu scope
lebih besar (ubah TinyMCE image dialog + rendering `artikel.php`),
saya tandai sebagai fase terpisah, di luar rencana ini.

### 3c. Resize — berlaku untuk SEMUA gambar yang diproses (JPG asli, PNG photo-type, PNG transparan)

- Kalau lebar > 1200px → resize proporsional ke lebar 1200px.
- Tidak pernah upscale gambar yang sudah lebih kecil dari 1200px.
- Berlaku juga untuk JPEG yang diupload langsung sebagai JPEG (bukan
  cuma hasil konversi dari PNG) — kalau JPEG-nya sendiri lebih lebar
  dari 1200px atau kualitasnya masih tinggi, tetap di-resize +
  di-recompress ke quality 80. Ini konsisten dengan instruksi poin 3
  Anda ("resize otomatis kalau dimensi melebihi kebutuhan wajar" —
  tidak dibatasi cuma untuk hasil konversi PNG).

### 3d. Kalau GD dan Imagick dua-duanya tidak tersedia

Skip seluruh § 3 (a/b/c), file asli yang sudah lolos validasi 3 MB
tersimpan apa adanya — sama seperti revisi 1, dicatat sekali ke
`error_log()` (bukan spam per-upload).

### 3e. Simpan hasil

Sama seperti revisi 1: file hasil optimasi disimpan sebagai file BARU
(bukan overwrite in-place). Kalau sukses → file asli (pra-optimasi)
dihapus, path/MIME di DB dialihkan ke file baru. Kalau proses gagal di
tengah jalan → file asli hasil langkah `move_uploaded_file()` tetap
dipakai apa adanya, tidak ada yang rusak.

---

## 4. Penamaan file & tipe MIME

- Output JPG: `<basename-slug>-<16 hex>.jpg`, MIME `image/jpeg`.
- Output WebP (kalau capability tersedia): `<basename-slug>-<16 hex>.webp`,
  MIME `image/webp`.
- PNG dengan transparansi nyata: tetap `.png` / `image/png`, cuma
  di-resize (kalau perlu) + re-encode lossless, ekstensi/MIME tidak
  berubah.
- `mimeExtMap` di `media-library.php` (baris 115-121) sudah punya
  entry untuk jpg/png/webp — tidak perlu tambahan, cuma logic baru yang
  menimpa `$ext`/MIME/path kalau optimasi mengubah format.
- Kalau optimasi di-skip (§ 3d, atau gagal) → path/MIME/ekstensi tetap
  hasil upload asli.

---

## 5. Update teks UI di form upload

Baris 538 di `media-library.php`, teks saat ini:

```
Allowed: JPG, PNG, WebP, GIF, PDF · Max 5 MB.
```

Diganti jadi (menyebutkan hard limit 3 MB, target ideal, format
rekomendasi, dan bahwa ada auto-konversi/kompresi — sesuai poin 5
instruksi Anda):

```
Allowed: JPG, PNG, WebP, GIF, PDF · Max 3 MB (hard limit — file di
atas ini ditolak). Target ideal: di bawah 300 KB. Rekomendasi: JPG
atau WebP untuk foto/ilustrasi — file akan otomatis dikompres/
dikonversi saat upload. PNG dipertahankan apa adanya untuk gambar
dengan transparansi (logo/ikon).
```

(Kalimat final akan disesuaikan ke gaya UI yang ada — ini draft isi,
bukan draft final wording untuk direview kata-per-kata; beri tahu kalau
mau saya susun ulang gaya bahasanya.)

Server-side error message untuk reject 3 MB — lihat § 2 (contoh persis
sudah sesuai kalimat yang Anda berikan).

---

## 6. Batasan scope (tidak berubah dari revisi 1)

- **Gambar yang sudah ada di `uploads/` TIDAK disentuh sama sekali** —
  pipeline ini cuma jalan di titik upload baru. Tidak ada proses
  batch/migrasi terhadap file lama.
- Tidak ada perubahan skema `media_library` — kolom existing cukup.
- `cms_process_file_uploads()` (`includes/upload.php`, dipakai
  banners/Site Settings/dll) — **tidak diubah**. `cms_image_optimize()`
  didesain generik supaya bisa dipakai ulang di situ kalau diminta
  nanti, tapi pemasangannya bukan bagian rencana ini.
- `<picture>`-based dual-format serving — fase terpisah kalau diminta.
- Kompresi GIF animasi — di luar scope.

---

## 7. Manifest file (rencana)

**Baru:**
- `cms-admin/includes/image-optimizer.php` — `cms_image_optimizer_capabilities()`,
  `cms_image_optimize()` (termasuk deteksi transparansi § 3a), helper
  privat per-library (GD/Imagick).

**Diubah:**
- `cms-admin/pages/media-library.php`:
  - Baris ~129: `$maxBytes` gambar 5MB → 3MB.
  - Baris ~131-132: pesan error reject, format baru sesuai § 2.
  - Blok baru dipanggil setelah `move_uploaded_file()` sukses (§ 3),
    sebelum data ditulis ke `media_library`.
  - Baris 538: teks hint form (§ 5).

**Tidak berubah:** `includes/upload.php`, modul upload lain, skema
`media_library`, semua file di `uploads/` yang sudah ada.

---

## 8. Pertanyaan yang butuh jawaban Anda sebelum saya mulai coding

1. **Paling penting, belum berubah dari revisi 1**: konfirmasi status
   GD/Imagick + dukungan WebP di **production** (§ 0). Kalau tidak ada
   sama sekali, limit 3 MB reject tetap bisa langsung jalan (murni PHP,
   tidak butuh GD/Imagick), tapi kompresi/konversi format baru aktif
   begitu ekstensinya tersedia.
2. **Format JPG-baseline + upgrade otomatis ke WebP kalau tersedia**
   (§ 3b) — ini jawaban saya untuk pertanyaan effort-vs-benefit Anda.
   Setuju dengan pendekatan single-file capability-based ini (bukan
   simpan WebP+JPG dua-duanya)?
3. **Deteksi transparansi PNG** (§ 3a) — setuju pakai scan alpha-channel
   sebagai proxy "butuh transparansi" (bukan klasifikasi semantik
   logo-vs-foto, karena itu tidak bisa dideteksi otomatis secara
   akurat)? Ini pendekatan standar tapi bukan 100% sempurna — kasus
   tepi seperti "foto dengan sedikit area transparan di pinggir" akan
   tetap dianggap "butuh transparansi" dan tidak dikonversi.
4. Max-width 1200px, quality 80, limit 3 MB, target ideal <300 KB —
   semua sebagai konstanta di kode (tidak dibuat tunable JSON seperti
   fitur GSC), kecuali Anda mau bisa diubah dari UI tanpa sentuh kode.
5. PNG dengan transparansi nyata yang dimensinya oversized — saya usul
   tetap resize (lossless, format tetap PNG) sesuai § 3a/3c. Setuju,
   atau untuk kasus ini dibiarkan saja apa adanya (tidak diresize sama
   sekali)?

---

## 9. Perluasan (21 Juli 2026): Banners & Site Settings

Setelah implementasi awal (§ 1-7, khusus Media Library/artikel), user
menemukan teks "Max size: 5 MB" masih tampil di form **New/Edit
Article** untuk field Featured image & OG image
(`cms-admin/pages/pages.php`) — ternyata teks itu basi: field itu tidak
punya upload sendiri, tombol "Choose from Media Library" cuma buka
modal *picker* (baca-saja dari tabel `media_library`), jadi upload
gambar baru tetap lewat `media-library.php` yang sudah 3 MB. **Diperbaiki**:
teks di `pages.php` (2 tempat: Edit & New Article form) diganti jadi
"Maks. 3 MB".

Ditemukan juga 2 modul upload lain yang benar-benar independen (bukan
sekadar teks basi) — **Banners** dan **Site Settings** (Logo/OG image)
— sama-sama masih pakai limit 5 MB nyata via helper bersama
`cms_process_file_uploads()` (`includes/upload.php`), sama sekali
terpisah dari `media-library.php`. Awalnya di luar scope § 1-7 (lihat
§ 6), tapi atas permintaan eksplisit user (banner & logo berdampak page
speed juga — logo bahkan tampil di semua halaman sekaligus), diperluas
sekarang:

- **`includes/upload.php`** — `cms_process_file_uploads()` ditambah
  spec key opsional `optimize` (bool). Kalau `true` dan MIME hasil
  deteksi adalah `image/jpeg`/`image/png`, dipanggil
  `cms_image_optimize()` (fungsi yang SAMA persis dari § 3 — resize
  1200px, WebP-first/JPG-fallback, deteksi transparansi PNG — tidak ada
  logic baru yang ditulis ulang) tepat setelah `move_uploaded_file()` +
  `chmod()` sukses, sebelum path final dicatat. SVG/ICO/WebP/GIF selalu
  dilewati apa pun nilai `optimize` (gate MIME sama seperti
  `media-library.php`).
- **Banners** (`actions/banners-store.php`, dipakai bareng oleh
  `banners-update.php`) — `desktop_image_file`/`mobile_image_file`:
  `max_bytes` 5MB→3MB, `optimize => true`.
- **Site Settings** (`actions/site-settings-update.php`) —
  `logo_file`: 5MB→3MB, `optimize => true` (no-op untuk upload
  SVG/WebP, cuma aktif kalau logo diupload sebagai JPG/PNG).
  `og_image_file`: 5MB→3MB, `optimize => true`. **`favicon_file`
  SENGAJA TIDAK DIUBAH** (tetap 1 MB, tidak ada `optimize`) — favicon
  butuh format ICO/PNG persis seukuran kecil (32×32), resize
  1200px/convert WebP tidak relevan dan akan merusak fungsinya sebagai
  favicon.
- Teks UI diupdate konsisten di `pages/banners.php` (2 tempat) dan
  `pages/site-settings.php` (2 dari 3 field — Logo & OG image; Favicon
  tetap "Max size: 1 MB").
- **Scope tidak berubah dari § 6**: gambar yang sudah ada (banner/logo/
  OG image lama, termasuk yang di atas 3 MB) **tidak disentuh sama
  sekali** — `cms_process_file_uploads()` cuma jalan kalau ada file baru
  di `$_FILES`, field yang tidak di-reupload dilewati total (baris 68
  `includes/upload.php`).
