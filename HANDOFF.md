# HANDOFF — SagaCrypto / WPM CMS

> **Baca file ini duluan** kalau kamu (Claude) baru masuk ke project ini di
> session/task baru. Ini bukan pengganti `SITEMAP.md` (yang tetap jadi
> sumber kebenaran paling lengkap & detail), tapi versi cepat buat langsung
> ngerti konteks tanpa harus baca ulang seluruh riwayat chat lama.

Terakhir di-update: **18 Juli 2026**

> Dokumen pendukung lain ada di `docs/`: `ROADMAP.md`, `DECISIONS.md`, `DEV_GUIDE.md`.

---

## 1. Apa ini

**SagaCrypto** — portal berita crypto (dulu "TheAwsoft", di-rebrand total).
Dua bagian:

- **Frontend publik** — `sagacrypto.com` — homepage, artikel, kategori,
  halaman crypto (harga live), pencarian.
- **Admin panel (CMS)** — `wpm.sagacrypto.com` — kelola artikel, SEO, ads,
  banners, integrasi Crypto API, AI content generation, dsb.

Stack: **PHP 8 procedural** (bukan framework), **PDO** ke MySQL. Tidak ada
build step, tidak ada Composer/npm dependency besar — vanilla PHP + vanilla
JS/CSS di frontend admin.

**Dokumen paling penting di repo**: `SITEMAP.md` (root project). Isinya:
peta lengkap semua route frontend + menu admin, DAN "Update Log" yang
mencatat riwayat setiap perubahan sejak awal project, urut tanggal. Kalau
butuh detail suatu fitur/keputusan desain — cek situ dulu sebelum tanya
user ulang.

---

## 2. Konvensi teknis (WAJIB diikuti, sudah berlaku konsisten di seluruh repo)

- **`declare(strict_types=1);` harus jadi statement PERTAMA** di setiap
  file PHP, persis setelah `<?php`. Tidak ada pengecualian.
- **PDO** selalu dibuat dengan `PDO::ATTR_EMULATE_PREPARES => false`.
- **Topologi deploy split-subdomain**: `sagacrypto.com` dan
  `wpm.sagacrypto.com` satu hosting/cPanel & satu MySQL server, tapi dua
  host berbeda secara HTTP. Struktur server sebenarnya (dikonfirmasi
  langsung di server, 18 Jul 2026 — koreksi dari asumsi lama di bawah):
  `public_html/` adalah document root `sagacrypto.com` (frontend), dan
  `public_html/cms-admin/` adalah document root `wpm.sagacrypto.com`
  (admin) — folder `cms-admin/` **tetap ada sebagai subfolder nyata di
  dalam `public_html/`, prefix-nya TIDAK di-flatten/dihilangkan saat
  deploy**. Yang membuatnya jadi domain terpisah adalah konfigurasi
  Document Root subdomain di cPanel yang menunjuk ke `public_html/cms-admin/`,
  bukan pemindahan/penghilangan folder secara fisik. (Working copy git
  ada terpisah di `~/repositories/sagacrypto-wpm/`, di luar `public_html/`
  — lihat `docs/DEPLOY_WORKFLOW.md` untuk alur deploy lengkap.)
- **`cms_public_base_prefix()`** (`cms-admin/includes/functions.php`) —
  satu-satunya cara yang benar buat bikin URL absolut dari admin balik ke
  frontend (misal buat preview gambar). JANGAN pakai `BASE_URL` mentah
  buat ini — itu domain admin sendiri, bukan domain frontend. Ini penyebab
  bug berulang (logo/banner preview kosong di production) sebelum
  ditemukan & dipolakan sebagai aturan wajib.
- **`cms_is_pages_subdirectory()`** — deteksi topologi (nested vs flat)
  lewat `SCRIPT_FILENAME`, dipakai `cms_action_href()`, `cms_api_href()`,
  `cms_nav_href()`, dkk buat generate link relatif yang selalu benar di
  kedua topologi.
- **Role-based access control** (baru, 15 Jul 2026) — `cms_require_role()`
  di `functions.php`. Role tersimpan di `admins.role`
  (`enum('superadmin','admin','editor')`, **lowercase, no spasi** — pernah
  ada bug dropdown yang kirim value salah format, sudah dibetulkan, tapi
  selalu double-check kalau nambah UI baru yang nyentuh role).
- **Migrasi SQL**: file bernomor urut di `cms-admin/migrations/` (next:
  **014**). Idempotent (`IF NOT EXISTS`/`INSERT IGNORE`) kalau non-destructive,
  atau eksplisit destructive + opt-in (harus dijalankan manual oleh user,
  BUKAN oleh Claude — sandbox ini tidak punya akses DB live). **Jangan
  pernah edit file migrasi lama** — selalu file baru, dan update
  `migrations/README.md` (tabel index) kalau relevan.
- **`SITEMAP.md` Update Log itu append-only** — jangan pernah nulis ulang
  entri lama, cuma tambah entri baru di bawah + update bagian "current
  state" (Feature Matrix dkk) kalau ada.

---

## 3. Batasan sandbox yang perlu diketahui

- **Tidak ada akses PHP/MySQL live** dari sandbox ini — semua verifikasi
  syntax dilakukan lewat pengecekan statis (brace/paren/tag balance pakai
  Python, karena `php -l` sering tidak tersedia dan `apt-get install`
  butuh sudo yang tidak ada). Migrasi destructive SELALU disiapkan sebagai
  file `.sql` opt-in, tidak pernah dieksekusi langsung.
- **Folder `outputs/` (scratchpad) tidak bisa overwrite/delete file** —
  kalau perlu bikin ulang file dengan nama sama, build dulu di `/tmp`
  lewat `mcp__workspace__bash`, baru `cp` ke `outputs/` dengan nama baru
  kalau perlu, atau langsung ke folder project (`wpm/`) yang MEMANG bisa
  di-overwrite/delete via bash.
- **Delivery pattern yang konsisten dipakai sepanjang project**: kalau
  selesai kerja, paket perubahan jadi `.zip` (struktur folder **mengikuti
  struktur repo apa adanya** — folder `cms-admin/` tetap dengan prefix-nya,
  TIDAK di-flatten; lihat § 2 di atas & `docs/DEPLOY_WORKFLOW.md` untuk
  kenapa), sertakan `BACA-DULU.txt` (petunjuk upload + checklist verifikasi
  manual dalam Bahasa Indonesia), lalu present ke user lewat
  `mcp__cowork__present_files`.

---

## 4. Yang sudah dikerjakan (ringkas — detail lengkap ada di SITEMAP.md § 4)

Urut kira-kira kronologis, fase-fase besar:

1. Hapus modul Products/Gallery lama (sisa pre-pivot "TheAwsoft").
2. Bangun modul Articles (kategori, tag, SEO fields, featured/trending).
3. Modul Advertisements — berkembang dari image-banner-only jadi 5 format
   (Text/Image/Video/Custom HTML/External Code), dengan live preview.
4. Featured/Pamungkas homepage section builder.
5. Integrasi Crypto API (provider-agnostic, cache, live ticker).
6. **Modul Livescore Sepak Bola dihapus total** (15 Jul 2026) — akan
   dibangun ulang sebagai project terpisah. Semua file/route/DB terkait
   sudah dibersihkan (lihat SITEMAP.md untuk daftar lengkap).
7. Modul Sitemaps (auto-generate, hook ke semua perubahan konten).
8. Site Settings, Banners, About Us — disambungkan penuh ke frontend
   (sebelumnya beberapa sempat orphan/tidak terpakai).
9. AI content generation (Generate SEO/Article/FAQ) — endpoint `cms-admin/api/`.
10. **Role-Based Access Control** (15 Jul 2026) — 3 tier
    (Editor/Admin/Super Admin) sekarang benar-benar membatasi akses,
    plus bug lama dropdown role (value salah format) dibetulkan sekalian.
    Paket delivery: `sagacrypto-admin-role-access.zip` (34 file + BACA-DULU.txt).
11. **Cleanup homepage** (18 Jul 2026) — section "Newsletter" (form
    berlangganan email) dihapus dari `index.php`. Dicek: `contact-submit.php`
    tidak punya logic yang membedakan subject Newsletter vs kontak biasa
    (handler generik, insert ke `contact_messages`) — tidak ada dead code
    yang tertinggal di backend.
12. **`.gitignore` diperluas** (18 Jul 2026) — config sensitif
    (`cms-admin/config/app.php`, file `.env*`), dokumen Growth Agent,
    `cms-admin/uploads/`, `.claude/`, `logs/`, dan dependency folders
    (`vendor/`, `node_modules/`) sekarang di-ignore. Konfigurasi repo,
    bukan perubahan kode aplikasi.

---

## 5. Belum selesai / masih pending

- **3 migrasi destructive belum dijalankan ke database live** (disiapkan,
  tinggal eksekusi manual oleh user):
  - `008_remove_products.sql`
  - `012_cleanup_unused_tables_columns.sql`
  - `013_remove_livescore_module.sql`
- **Fase 7: App Promotion module** — masih on hold (badge App
  Store/Google Play di homepage baru sebatas placeholder + logo, belum
  ada app beneran).

---

## 6. Hal yang perlu diingat kalau lanjut kerja di sini

- User (Donnie) komunikasi dalam **Bahasa Indonesia casual** ("bro", dsb)
  — balas dengan gaya yang sama, ringkas, langsung ke poin, jangan
  bertele-tele.
- User punya preferensi eksplisit: **concise & direct**, minim basa-basi.
- Selalu **verifikasi dependency sebelum hapus apa pun** yang namanya
  generik (pernah diminta eksplisit soal ini waktu hapus modul Livescore)
  — grep penuh codebase dulu, jangan asumsi dari nama file/kolom doang.
- **Jangan pernah sentuh tabel/fitur Crypto** kecuali memang diminta —
  ini fitur inti yang aktif dipakai, beda dari modul-modul lain yang
  sering di-cleanup.
- Setiap kerjaan yang mengubah struktur (hapus/tambah menu, tabel, route)
  → **wajib update `SITEMAP.md`** sebelum dianggap selesai.
