# Panduan Deploy WPM/SagaCrypto ke sagacrypto.com (cPanel + Cloudflare)

## Kenapa dipisah jadi 2 ZIP tapi tetap harus digabung di satu folder

Penting untuk dipahami dulu sebelum upload: `cms-admin/` **tidak boleh** ditaruh di lokasi terpisah dari file utama, walaupun nanti diakses lewat subdomain `wpm.sagacrypto.com`. Ada 2 alasan teknis:

1. Fitur **Prompt Control** di admin panel butuh file `services/PromptLoader.php`, yang dicari lewat path relatif 2 folder di atas `cms-admin/pages/` — artinya `services/` wajib jadi folder sejajar (sibling) dengan `cms-admin/`.
2. Upload gambar (produk, galeri, banner, dll) disimpan di folder `uploads/` yang juga sejajar dengan `cms-admin/`, dan dipakai bareng oleh halaman publik (`index.php`) untuk menampilkan gambar tadi.

Solusinya: kedua ZIP ini **sama-sama diekstrak ke `public_html/` yang sama**. `wpm.sagacrypto.com` nanti dibuat sebagai subdomain yang document root-nya diarahkan ke folder `public_html/cms-admin` yang SAMA (bukan folder baru/copy terpisah) — cPanel punya fitur ini secara bawaan. Dengan begitu:

- `sagacrypto.com` → melayani isi `public_html/` (index.php, dst.)
- `wpm.sagacrypto.com` → melayani isi `public_html/cms-admin/` (folder yang sama persis)
- Semua path relatif (`services/`, `uploads/`) tetap jalan normal karena secara fisik masih satu folder tree yang sama. Tidak perlu ubah kode apa pun.

## File yang saya siapkan

1. **`sagacrypto-frontend.zip`** — isi: `index.php`, `contact-submit.php`, `assets/` (CSS & JS). Extract ke **`public_html/`** (root).
2. **`sagacrypto-admin-panel.zip`** — isi: folder `cms-admin/` lengkap + folder `services/`. Extract juga ke **`public_html/`** (root) — bukan ke subfolder lain — supaya hasil akhirnya `public_html/cms-admin/...` dan `public_html/services/...`.

Catatan: `wpm_cms.sql` **sengaja tidak saya masukkan** ke ZIP manapun — file database sebaiknya diimpor langsung lewat phpMyAdmin di cPanel, bukan diupload ke folder web (kalau nyangkut di `public_html`, isinya bisa diakses publik lewat URL langsung — risiko keamanan).

---

## Langkah 1 — Setup DNS di Cloudflare

Asumsi: domain `sagacrypto.com` sudah terdaftar di registrar, dan Cloudflare akan jadi DNS manager-nya.

1. **Kalau domain belum pakai Cloudflare**: tambahkan site di dashboard Cloudflare (`Add a Site` → masukkan `sagacrypto.com`), lalu Cloudflare akan kasih 2 nameserver (misal `xxx.ns.cloudflare.com`). Ganti nameserver domain di registrar (tempat kamu beli domain) ke 2 nameserver itu. Proses propagasi bisa 1–24 jam.

2. **Cari IP server hosting cPanel kamu** — biasanya ada di email welcome hosting, atau di cPanel → "Server Information" (cari "Shared IP Address" / "Dedicated IP Address").

3. Di Cloudflare dashboard → **DNS → Records**, tambahkan:

   | Type | Name | Content | Proxy status |
   |---|---|---|---|
   | A | `@` | `<IP server hosting>` | DNS only (awan abu-abu) dulu |
   | A | `www` | `<IP server hosting>` | DNS only dulu |
   | A | `wpm` | `<IP server hosting>` | DNS only dulu |

   Kenapa "DNS only" dulu (bukan langsung "Proxied"/awan oranye): supaya cPanel AutoSSL bisa memverifikasi domain & menerbitkan sertifikat SSL dulu tanpa terhalang proxy Cloudflare. Setelah SSL aktif dan situs sudah bisa dibuka normal, boleh diubah ke **Proxied** (awan oranye) untuk dapat CDN + proteksi Cloudflare.

4. Tunggu propagasi DNS (cek dengan `https://dnschecker.org`, masukkan `sagacrypto.com` dan `wpm.sagacrypto.com`).

---

## Langkah 2 — Setup di cPanel

### 2.1 Buat database MySQL
1. cPanel → **MySQL® Databases** → buat database baru (misal `wpm`) → hasilnya jadi `cpaneluser_wpm`.
2. Buat user MySQL baru + password kuat → tambahkan user itu ke database tadi dengan **All Privileges**.
3. Catat 3 hal ini: nama database lengkap, username lengkap, password.

### 2.2 Import database
1. cPanel → **phpMyAdmin** → pilih database yang baru dibuat.
2. Tab **Import** → pilih file `wpm_cms.sql` (yang sudah kamu kasih ke saya) → klik **Go**.

### 2.3 Upload & extract file
1. cPanel → **File Manager** → masuk ke `public_html`.
2. Upload `sagacrypto-frontend.zip` → klik kanan → **Extract** (ekstrak langsung di `public_html`).
3. Upload `sagacrypto-admin-panel.zip` → **Extract** juga langsung di `public_html` (akan menghasilkan folder `public_html/cms-admin/` dan `public_html/services/`).
4. Hapus kedua file `.zip` setelah selesai diekstrak (tidak perlu disimpan di `public_html`).

### 2.4 Buat folder `uploads/`
Buat struktur folder ini di `public_html/uploads/` (lewat File Manager → New Folder), izin akses 755:
```
uploads/banners
uploads/landing
uploads/media
uploads/product-images
uploads/products
uploads/site/favicon
uploads/site/logo
uploads/site/seo
uploads/testimonials
```

### 2.5 Isi kredensial database
Buka `public_html/cms-admin/config/database.php` lewat File Manager (Edit), ganti 4 baris ini dengan data dari langkah 2.1:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpaneluser_wpm');        // ganti sesuai punya kamu
define('DB_USER', 'cpaneluser_wpmadmin');   // ganti sesuai punya kamu
define('DB_PASS', 'password_asli_kamu');
```

### 2.6 Buat subdomain `wpm`
1. cPanel → **Domains** (atau **Subdomains**, tergantung versi cPanel).
2. Buat subdomain baru: `wpm.sagacrypto.com`.
3. **Document Root**: arahkan ke `public_html/cms-admin` (folder yang SUDAH ada dari hasil extract tadi — jangan biarkan cPanel membuat folder baru otomatis, pilih folder yang sudah ada).

### 2.7 Aktifkan SSL
1. cPanel → **SSL/TLS Status** → centang `sagacrypto.com`, `www.sagacrypto.com`, `wpm.sagacrypto.com` → **Run AutoSSL**.
2. Tunggu beberapa menit sampai sertifikat terbit untuk ketiganya.
3. Setelah semua bisa dibuka via `https://` tanpa warning, kembali ke Cloudflare dan boleh ubah proxy status ke **Proxied** (awan oranye) kalau mau pakai CDN Cloudflare.

---

## Langkah 3 — Testing

1. Buka `https://sagacrypto.com` → halaman crypto portal harus tampil.
2. Coba isi form Kontak di halaman utama → cek pesan masuk di `https://wpm.sagacrypto.com/login.php` (menu Contact Messages).
3. Buka `https://wpm.sagacrypto.com/login.php` → login admin panel harus tampil normal, bisa login.
4. Cek menu **Products**, **Gallery**, **Testimonials** — pastikan tidak ada error skema (sudah ada auto-migration, harusnya langsung sembuh sendiri begitu tabel diakses pertama kali).
5. Coba upload gambar lewat Media Library / Products → pastikan file benar-benar tersimpan di `uploads/` dan bisa tampil.

---

## Hal yang perlu kamu siapkan sendiri (tidak bisa saya isi otomatis)

- IP server hosting cPanel kamu (untuk record DNS di atas).
- Kredensial database MySQL yang di-generate cPanel (langkah 2.1–2.5).
- Password admin panel — cek tabel `admins` di database (email login ada di situ); kalau lupa password, reset lewat phpMyAdmin (update kolom `password_hash` pakai hash bcrypt baru).
