# Growth Agent × Google Search Console — Rencana Integrasi

> **Status: SUDAH DIIMPLEMENTASI (18 Jul 2026), belum pernah dites end-to-end
> di server nyata.** Dokumen ini awalnya murni desain untuk direview sebelum
> kode ditulis — sudah di-approve, dan seluruh kode di § 9 (Manifest) sudah
> dibuat sesuai rencana ini (lihat `SITEMAP.md` § Update Log 18 Jul 2026
> untuk ringkasan implementasinya). Dokumen ini dibiarkan apa adanya sebagai
> catatan desain/rationale, bukan diperbarui jadi dokumentasi pasca-implementasi
> — kalau ada detail yang berubah saat implementasi (jarang, cuma
> penyesuaian kecil), itu tercatat di komentar kode masing-masing file, bukan
> di sini. **Belum divalidasi dengan service account/property GSC
> sungguhan** — migrasi `015` belum dijalankan ke database live, dan belum
> ada yang connect lewat `gsc-settings.php`.

Ditulis: 18 Juli 2026. Sumber yang dibaca sebelum menulis ini:
`HANDOFF.md`, `SITEMAP.md`, `cms-admin/pages/growth-agent.php`,
`cms-admin/includes/growth-agent-service.php`,
`services/GrowthAgentPromptBuilder.php`,
`cms-admin/pages/seo-recommendation-review.php`,
`cms-admin/migrations/014_growth_agent.sql`,
`cms-admin/includes/crypto-api.php`, `cms-admin/pages/ai-credentials.php`,
`cms-admin/includes/schema-guard.php`.

---

## 0. Keputusan yang sudah dikunci di diskusi

### 0.1 — DIPUTUSKAN: tanpa cron sama sekali, murni lazy-on-pageview

Project ini tidak punya cron/queue apa pun (fakta berulang di
`sitemap-service.php`, `growth-agent-service.php`, `SITEMAP.md`). Atas
keputusan Anda, kita **tidak** bikin cron job/OS-level entry point baru —
tidak ada folder `cms-admin/cron/`, tidak ada dokumentasi setup cPanel Cron
Jobs.

Sebagai gantinya: pengecekan "udah lebih dari 24 jam sejak fetch
terakhir? kalau iya, fetch sekarang" ditempel di awal `growth-agent.php`
(mirip pola `cms_growth_agent_cleanup_old_jobs()` yang jalan tiap page
load). Konsekuensi & mitigasinya:

- Cadence jadi "whenever admin buka halaman Growth Agent, kalau udah
  lewat 24 jam", bukan benar-benar harian di jam tetap.
- Untuk menutup risiko gap kalau halaman jarang dibuka, `fetch_lookback_days`
  default **dinaikkan dari 3 hari → 14 hari** (lihat § 1) — GSC tidak
  keberatan query range lebih lebar (biaya cuma request sedikit lebih
  lama), jadi seberapa pun jarang halaman dibuka, begitu dibuka lagi data
  yang ketinggalan otomatis ter-backfill selama masih dalam radius 14
  hari. Cukup untuk kebutuhan analisis tren SEO yang memang tidak butuh
  presisi harian.
- Kalau nanti ternyata butuh cadence yang lebih pasti (misal situs makin
  besar & datanya makin penting dicek tiap hari tanpa syarat "ada yang
  buka halaman"), opsi cron beneran masih bisa ditambahkan belakangan
  tanpa mengubah `cms_gsc_fetch_and_cache()` sama sekali — tinggal tambah
  satu entry point baru yang manggil fungsi yang sama.

### 0.2 — Google API Client Library (Composer) vs REST manual — saya rekomendasikan TANPA Composer

HANDOFF.md eksplisit: *"Tidak ada build step, tidak ada Composer/npm
dependency besar."* Google API Client Library resmi untuk PHP
(`google/apiclient`) itu berat — dependency tree besar (Guzzle dkk),
butuh `composer install`, dan akan jadi **dependency Composer pertama** di
seluruh project ini.

**Analisis:** Search Console API cuma butuh 2 hal — (1) tukar service
account JSON jadi access token via JWT bearer flow, (2) panggil REST
endpoint biasa. Keduanya bisa dikerjakan murni pakai fungsi PHP bawaan:

- JWT signing pakai `openssl_sign()` dengan algoritma RS256 (ekstensi
  `openssl` — **sudah jadi dependency wajib project ini**, dipakai
  `cms_ai_encrypt()`/`cms_ai_decrypt()` di `ai-helpers.php`, jadi tidak ada
  ekstensi baru yang perlu diaktifkan di server).
- Tukar JWT jadi access token: satu `POST` cURL ke
  `https://oauth2.googleapis.com/token` — pola identik dengan
  `cms_crypto_http_get()` di `crypto-api.php`.
- Query data: satu `POST` cURL ke
  `https://searchconsole.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query`
  dengan `Authorization: Bearer <token>` — sama persis pola cURL yang
  sudah ada di `crypto-api.php`.

**Rekomendasi saya: TANPA Composer, hand-rolled JWT + REST langsung**,
konsisten 100% dengan pola `crypto-api.php` yang sudah ada (provider
settings di tabel DB, cURL manual, tidak ada SDK eksternal). Ini juga
berarti tidak ada risiko baru soal maintenance dependency/security patch
dari package pihak ketiga. Kalau nanti butuh lebih dari sekadar Search
Console (misal Google Analytics juga), baru worth dipertimbangkan ulang
apakah saatnya Composer minimal masuk — tapi untuk scope ini, tidak perlu.

**Yang saya butuh dari Anda:** konfirmasi setuju pakai REST manual
(rekomendasi saya), atau Anda memang mau introduce Composer sekalian
(kalau ada rencana integrasi Google API lain di masa depan yang bikin SDK
lebih worth-it).

---

## 1. Skema database — migrasi baru `015_gsc_search_console.sql`

Nomor urut berikutnya setelah `014_growth_agent.sql` (dicek di
`migrations/README.md`). Idempotent (`CREATE TABLE IF NOT EXISTS`), sama
seperti pola migrasi lain — juga akan punya auto-create lazy lewat
`cms_ensure_table()` di file service baru (`cms-admin/includes/gsc-api.php`),
persis pola `cms_crypto_ensure_schema()`.

**2 tabel baru** (bukan lebih — error log reuse tabel `api_error_log` yang
sudah ada, sama seperti Crypto/Livescore dulu, `source='gsc'`):

### `gsc_settings` (singleton, 1 baris — sama seperti `crypto_api_settings`)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `service_account_email` | VARCHAR(255) NULL | Di-parse dari field `client_email` JSON saat disimpan — **tidak sensitif**, aman ditampilkan di UI (mirip `key_last4` di `ai_credentials`, biar admin tau akun mana yang terhubung tanpa expose private key). |
| `service_account_json_enc` | LONGTEXT NULL | Seluruh isi file JSON service account, **dienkripsi** pakai `cms_ai_encrypt()` yang sudah ada (AES-256-CBC) — reuse fungsi, bukan bikin skema enkripsi baru. Tidak pernah didekripsi untuk ditampilkan lagi ke UI setelah disimpan (sama aturan seperti `ai_credentials.api_key_enc`). |
| `site_url` | VARCHAR(255) NULL | Property GSC yang dipilih admin, format `sc-domain:sagacrypto.com` atau `https://sagacrypto.com/` sesuai apa yang dikembalikan `sites.list`. |
| `is_active` | TINYINT(1) DEFAULT 0 | |
| `fetch_lookback_days` | INT UNSIGNED DEFAULT 14 | GSC punya delay ~2-3 hari + data bisa direvisi setelah muncul pertama kali. Default dinaikkan ke 14 hari (bukan cuma "kemarin") karena tidak ada cron (§ 0.1) — tiap kali lazy-fetch jalan, dia re-pull 14 hari terakhir sekaligus, jadi aman walau halaman Growth Agent jarang dibuka. |
| `fetch_window_days` | INT UNSIGNED DEFAULT 90 | Retensi — baris `gsc_query_data` lebih tua dari ini dibersihkan tiap fetch (pola sama seperti `cms_growth_agent_cleanup_old_jobs()`), supaya tabel tidak tumbuh selamanya. |
| `last_fetch_status` | VARCHAR(20) NULL | `success` / `failed` — pola sama `crypto_api_settings.last_test_status`. |
| `last_fetch_message` | VARCHAR(255) NULL | |
| `last_fetch_rows` | INT UNSIGNED NULL | Jumlah baris ditulis pada fetch terakhir — diagnostik cepat tanpa perlu query tabel data. |
| `last_fetch_at` | TIMESTAMP NULL | |
| `created_at`, `updated_at` | TIMESTAMP | |

### `gsc_query_data` (cache mentah hasil tarik API)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `query` | VARCHAR(255) NOT NULL | Dimensi `query` dari GSC. |
| `page_url` | VARCHAR(500) NOT NULL | Dimensi `page` (URL lengkap) dari GSC. |
| `matched_page_id` | INT UNSIGNED NULL | **Kolom kunci untuk 3 jenis analisis.** Diresolve saat fetch: cocokkan `page_url` terhadap `pages.slug`/`pages.canonical_url` yang published. `NULL` kalau tidak ada artikel yang cocok — inilah sinyal langsung buat "Tipe 3: ide artikel baru". Tidak ada FK constraint (konsisten dengan konvensi project — lihat catatan di `014_growth_agent.sql`). |
| `clicks` | INT UNSIGNED DEFAULT 0 | |
| `impressions` | INT UNSIGNED DEFAULT 0 | |
| `ctr` | DECIMAL(7,4) NULL | Dari GSC langsung (0.0–1.0), tidak dihitung ulang manual. |
| `position` | DECIMAL(6,2) NULL | Rata-rata posisi dari GSC. |
| `data_date` | DATE NOT NULL | Dimensi `date` dari GSC — tanggal data ini terjadi, BUKAN tanggal fetch. |
| `dedupe_hash` | CHAR(32) NOT NULL | `MD5(query + '|' + page_url + '|' + data_date)`, dihitung di PHP sebelum INSERT. **UNIQUE KEY** di kolom ini — cara aman melakukan upsert idempotent tanpa kena limit panjang index MySQL kalau langsung bikin unique key gabungan dari 2 kolom VARCHAR panjang + DATE (bisa lebih dari batas byte index InnoDB untuk `utf8mb4`). Fetch ulang hari yang sama = `INSERT ... ON DUPLICATE KEY UPDATE`, bukan duplikat baris. |
| `fetched_at` | TIMESTAMP | |

Index tambahan: `KEY idx_gsc_page (matched_page_id)`, `KEY idx_gsc_date (data_date)`,
`KEY idx_gsc_query (query(100))` (prefix index, cukup untuk pencarian/grouping).

**Error log:** reuse `api_error_log` (`source = 'gsc'`) — tabel ini sudah
ada sejak Fase 5 (Crypto API) dan sudah dipakai bareng beberapa modul,
tidak perlu tabel log baru.

### Tambahan: kolom `priority` di tabel `growth_agent_jobs` (ALTER, bukan tabel baru)

Sesuai permintaan — tiap rekomendasi yang datang dari scan GSC (3 tipe di
§ 4) perlu label **HIGH / NORMAL** biar admin bisa langsung lihat mana yang
paling worth ditindaklanjuti dulu di antrian review.

Migrasi `015` juga akan berisi (selain 2 `CREATE TABLE IF NOT EXISTS` di
atas):

```sql
ALTER TABLE `growth_agent_jobs`
  ADD COLUMN IF NOT EXISTS `priority` ENUM('normal','high') NOT NULL DEFAULT 'normal' AFTER `status`;
```

(Pola yang sama seperti migrasi `003`/`011` yang juga menggabungkan
`CREATE TABLE` tabel baru + `ALTER TABLE` tabel lama yang sudah ada dalam
satu file migrasi — bukan pelanggaran aturan "jangan edit migrasi lama",
karena `014_growth_agent.sql` sendiri tidak disentuh, ini `ALTER` dari
migrasi baru.)

**Kenapa kolom asli, bukan disisipkan ke dalam `output_json`:** supaya
bisa langsung dipakai buat sorting/filter di query SQL tabel "Recent jobs"
tanpa parse JSON tiap render, dan konsisten dengan `status` yang juga
kolom asli meski sama-sama "meta info" tentang job.

**Job non-GSC** (seo_meta/article_draft/faq generate manual yang sudah
ada) otomatis dapat `priority = 'normal'` (default kolom), tidak pernah
`'high'` — label ini murni untuk hasil analisis data GSC.

**Cara `priority` dihitung** (di masing-masing fungsi scan, § 4), threshold
awal yang saya usulkan (bisa disesuaikan pas implementasi setelah lihat
data real):

| Tipe | HIGH kalau | NORMAL kalau |
|---|---|---|
| 1 — CTR rendah | impressions ≥ 1000 DAN ctr < 1% | sisanya (masih masuk kriteria scan tapi tidak se-signifikan) |
| 2 — Striking distance | posisi 5–10 DAN impressions ≥ 500 | posisi 11–15, atau impressions di bawah itu |
| 3 — Ide artikel baru | impressions ≥ 1000 (demand pencarian kuat, nol konten) | impressions di bawah itu tapi masih lolos ambang minimum scan |

---

## 2. Autentikasi & koneksi property (UI baru)

Halaman baru **`cms-admin/pages/gsc-settings.php`** — superadmin-only
(`cms_require_role(['superadmin'])`), tier sama seperti `ai-credentials.php`
dan `crypto-api.php` karena sama-sama menyimpan kredensial mentah. **Bukan**
bagian dari `growth-agent.php` (yang admin+ tier) — dipisah persis alasan
yang sama kenapa `ai-credentials.php` terpisah dari `ai-sandbox.php`.

Alur di halaman ini:
1. Textarea "Paste service account JSON" (bukan file upload — konsisten
   dengan tidak adanya infrastruktur upload file baru yang perlu
   dibangun; admin cukup buka file JSON yang didownload dari Google Cloud
   Console, copy-paste isinya).
2. Saat disimpan: validasi JSON valid, cek field wajib (`client_email`,
   `private_key`, `token_uri`) ada, lalu **langsung test koneksi** (mirip
   tombol "Test Connection" di `crypto-api.php`) — coba generate access
   token, kalau gagal jangan simpan, tampilkan pesan error jelas (paling
   umum: "service account belum ditambahkan sebagai user di properti GSC
   ini").
3. Kalau token berhasil didapat: panggil `GET
   https://www.googleapis.com/webmasters/v3/sites` (list situs yang
   accessible oleh service account ini), tampilkan sebagai `<select>`
   property untuk dipilih admin. Ini penting — service account harus
   ditambahkan manual dulu sebagai "User" (permission "Full" atau
   "Restricted" cukup, read-only scope yang kita minta) di Search Console
   property settings, langkah ini **di luar kendali kode**, harus
   didokumentasikan jelas di UI (helper text) supaya admin tidak bingung
   kalau daftar property-nya kosong.
4. Simpan pilihan → `gsc_settings.site_url` + `is_active = 1`.

Scope OAuth yang diminta: `https://www.googleapis.com/auth/webmasters.readonly`
(read-only — least privilege, kita tidak pernah menulis apa pun ke GSC).

---

## 3. Fetch pipeline — `cms-admin/includes/gsc-api.php` (file baru)

Nama fungsi mengikuti pola persis `crypto-api.php` (`cms_gsc_*` sebagai
prefix, `!function_exists()` guard, dst):

- `cms_gsc_ensure_schema($pdo)` — `cms_ensure_table()` untuk 2 tabel di
  atas, pola identik `cms_crypto_ensure_schema()`.
- `cms_gsc_get_settings($pdo)` — baca baris singleton.
- `cms_gsc_generate_jwt(array $serviceAccount): string` — bangun +
  sign JWT (header+claims base64url, `openssl_sign` RS256 pakai
  `private_key` dari JSON).
- `cms_gsc_get_access_token($pdo): array{ok,token,error}` — tukar JWT ke
  `oauth2.googleapis.com/token`, **tidak cache token di DB** (token
  cuma valid 1 jam, lebih simpel re-generate tiap request daripada
  urus expiry tracking — biaya generate JWT+token exchange itu murah,
  1 request tambahan per fetch run, bukan per query).
- `cms_gsc_http_post_json(string $url, array $body, string $bearerToken): array` —
  pola identik `cms_crypto_http_get()` tapi untuk POST+JSON+Bearer auth.
- `cms_gsc_list_sites($pdo): array` — dipakai halaman settings (langkah 3
  di atas).
- `cms_gsc_fetch_and_cache($pdo, bool $forceRefresh = false): array{ok,rows_written,error}` —
  **fungsi utama**, dipanggil baik oleh cron script maupun tombol "Refresh
  Data" manual maupun fallback lazy-check:
  1. Ambil access token.
  2. `POST searchAnalytics/query` dengan `dimensions: ["query","page","date"]`,
     `startDate`/`endDate` dihitung dari `fetch_lookback_days`, `rowLimit`
     tinggi (misal 5000, GSC API max 25000 per call — cukup untuk site ini,
     tidak perlu pagination di iterasi pertama, dicatat sebagai known
     limitation kalau situs tumbuh besar nanti).
  3. Untuk tiap baris hasil: hitung `dedupe_hash`, resolve `matched_page_id`
     (query 1x semua `pages` published slug/canonical ke memori PHP di awal
     loop, cocokkan di PHP — bukan query per-baris ke DB, biar tidak N+1).
  4. `INSERT ... ON DUPLICATE KEY UPDATE` per baris (atau batched, tergantung
     volume — akan dites saat implementasi).
  5. Hapus baris lebih tua dari `fetch_window_days`.
  6. Update `gsc_settings.last_fetch_*`.
  7. Kegagalan apa pun → `cms_crypto_log_error()`-style ke `api_error_log`
     (`source='gsc'`), fungsi tetap `return` rapi (tidak pernah throw ke
     caller), sama filosofi seperti seluruh Growth Agent service
     ("logging failure must never break the caller").

---

## 4. Logic analisis — 3 jenis peluang

Fungsi baru di `cms-admin/includes/growth-agent-service.php` (bukan file
terpisah — biar tetap satu tempat untuk semua "scan" Growth Agent,
konsisten dengan `cms_growth_agent_scan_seo_recommendations()` yang sudah
ada di situ).

| # | Kriteria SQL (ringkas) | `job_type` | Lewat halaman review mana |
|---|---|---|---|
| 1 | `matched_page_id IS NOT NULL`, `SUM(impressions)` ≥ threshold, `SUM(clicks)/SUM(impressions)` < threshold, belum pernah di-scan tipe ini | **`seo_recommendation`** (reuse persis, bukan tipe baru) | `seo-recommendation-review.php` **tanpa perubahan** |
| 2 | `matched_page_id IS NOT NULL`, `AVG(position)` antara 5–15, impressions ≥ floor | `gsc_content_optimization` (baru) | Approve/Reject generik di `growth-agent.php` (**sudah otomatis support job_type apa pun selain `seo_recommendation`** — tidak perlu ubah kode di sana) |
| 3 | Grouped by `query` saja: semua baris query itu punya `matched_page_id IS NULL`, `SUM(impressions)` ≥ threshold | `gsc_article_idea` (baru) | Approve/Reject generik, sama seperti #2 |

**Kenapa Tipe 1 reuse `job_type='seo_recommendation'` persis:**
`seo-recommendation-review.php` query jobnya dengan
`WHERE j.job_type = 'seo_recommendation'` hardcoded — pakai string yang
sama adalah **syarat wajib** supaya halaman review yang sudah ada bisa
langsung pakai tanpa modifikasi, bukan cuma soal konsistensi penamaan.
Bedanya cuma cara *memilih* artikel mana yang discan (dulu: "semua
published belum pernah discan", sekarang tambahan: "yang GSC bilang
impression tinggi CTR rendah") — flow AI-call, parse, log job-nya reuse
1:1, cukup refactor loop generate-nya jadi helper privat yang dipanggil
dua-duanya (fungsi lama tetap ada untuk kasus non-GSC).

**Exclusion logic** (biar tidak re-flag artikel yang sama berulang) —
sama pola seperti fungsi lama: `page_id NOT IN (SELECT page_id FROM
growth_agent_jobs WHERE job_type = '<tipe>' AND status IN
('manual_action','succeeded'))`.

**Trigger:** manual via tombol baru ("Scan GSC Opportunities") di
`growth-agent.php`, **bukan otomatis tiap fetch** — konsisten dengan pola
"scan_seo" yang sudah ada (tarik data ≠ generate rekomendasi, dua langkah
terpisah supaya admin bisa kontrol kapan AI call terjadi, karena itu yang
biaya token).

---

## 5. Integrasi ke `GrowthAgentPromptBuilder`

**Tidak perlu ubah class ini sama sekali.** `buildContext($agentKey,
$jobType, ...)` sudah generic — filter style rules + few-shot examples
berdasarkan `job_type` apa pun yang dikirim, jadi otomatis akan mulai
mengumpulkan contoh untuk `gsc_content_optimization`/`gsc_article_idea`
begitu ada job yang di-approve dengan tipe itu (persis cara kerja fungsi
ini untuk tipe-tipe lain sekarang — mulai kosong, terisi seiring approval).

**Yang baru:** system prompt & user prompt untuk 2 job_type baru (ditulis
di fungsi scan masing-masing, pola sama seperti
`$defaultSystemPrompt`/`$userPrompt` di `cms_growth_agent_scan_seo_recommendations()`).
User prompt untuk Tipe 2 & 3 akan menyertakan angka GSC mentah (query,
impressions, posisi rata-rata) sebagai konteks buat AI, bukan cuma
judul/konten artikel seperti Tipe 1.

**Agent key — usul baru:** Tipe 1 tetap pakai `agent_key='seo_agent'`
(sama seperti sekarang). Tipe 2 & 3 saya usulkan pakai agent_key baru
**`growth_agent`** (bukan reuse `seo_agent`) — karena tugasnya beda sifat
(ideasi/strategi konten vs. rewrite meta tag ketat), masuk akal kalau
admin mau kasih system prompt/model/temperature yang beda untuk itu lewat
AI Agent Settings yang sudah ada. Konsekuensinya: admin harus setup agent
baru ini dulu di **AI Agent Settings** sebelum Tipe 2/3 bisa jalan — tapi
`cms_ai_resolve_agent()` sudah handle skenario "belum dikonfigurasi" ini
dengan aman (pesan error jelas, bukan crash), jadi tidak ada risiko kalau
admin belum sempat setup. **Ini juga saya tandai sebagai poin yang boleh
Anda override** kalau lebih suka reuse `seo_agent` saja biar tidak perlu
setup tambahan.

---

## 6. UI — perubahan ke `growth-agent.php`

Perubahan aditif saja (tidak menghapus apa pun yang sudah ada):

- Card status baru di bagian atas: "GSC: Connected (sagacrypto.com) /
  Not connected" + link ke `gsc-settings.php`, + "Last fetch: <waktu>
  (<n> rows)".
- Tombol **"Refresh Data"** — POST ke action baru
  `cms-admin/actions/gsc-refresh.php` (role sama seperti halaman ini,
  `superadmin`+`admin`), panggil `cms_gsc_fetch_and_cache($pdo, true)`,
  redirect balik dengan flash message (pola identik tombol "Scan for SEO
  improvements" yang sudah ada).
- Tombol baru **"Scan GSC Opportunities"** — trigger analisis 3 tipe di
  atas sekaligus (atau 3 tombol terpisah per tipe — akan diputuskan saat
  implementasi berdasarkan mana yang lebih jelas buat admin; saya condong
  ke 1 tombol yang jalankan ketiganya sekali klik, konsisten dengan "Scan
  for SEO improvements" yang juga satu tombol).
- Tabel "Recent jobs" yang sudah ada **otomatis** menampilkan job_type
  baru (`gsc_content_optimization`, `gsc_article_idea`) tanpa perubahan
  struktur — kolom "Job" cuma nge-print `$job['job_type']` apa adanya.
- **Perubahan kecil yang perlu ditambah** ke tabel "Recent jobs": satu
  kolom/badge baru untuk `priority` — pill **HIGH** (warna mencolok, mis.
  `pill--warn` yang sudah ada) di sebelah pill status yang sudah ada,
  hanya muncul kalau `priority = 'high'` (job normal tidak perlu badge
  tambahan, biar tidak berisik). Approve/Reject tetap tombol yang sama
  persis yang sudah ada — HIGH/NORMAL cuma soal urutan perhatian visual,
  bukan alur approval baru.

---

## 7. Lazy-fetch trigger (pengganti cron — lihat § 0.1)

Tidak ada file baru untuk ini — cukup beberapa baris di awal
`growth-agent.php` (setelah `cms_growth_agent_cleanup_old_jobs()` yang
sudah ada di situ):

```php
// Lazy GSC fetch — no cron in this codebase (§ 0.1 keputusan eksplisit,
// bukan default project). Re-fetch kalau sudah lebih dari 24 jam sejak
// fetch terakhir, dan GSC settings memang aktif/tersambung.
cms_gsc_fetch_if_stale($pdo, 24);
```

`cms_gsc_fetch_if_stale($pdo, int $maxAgeHours)` (fungsi baru di
`gsc-api.php`) cukup baca `gsc_settings.last_fetch_at`, kalau `is_active`
dan umurnya lebih dari `$maxAgeHours` jam → panggil
`cms_gsc_fetch_and_cache($pdo, false)`. Sama seperti pemanggil lain,
tidak pernah throw ke caller (kalau gagal, halaman tetap render normal,
error tercatat di `api_error_log`).

---

## 8. Dependency PHP baru

**Tidak ada** (lihat § 0.2) — cuma pakai ekstensi yang sudah jadi
dependency project ini: `curl`, `openssl`, `json` (semua sudah dipakai di
`ai-helpers.php`/`crypto-api.php`).

---

## 9. Manifest file (rencana, belum dibuat)

**Baru:**
- `cms-admin/migrations/015_gsc_search_console.sql` (2 `CREATE TABLE` +
  1 `ALTER TABLE growth_agent_jobs ADD COLUMN priority`)
- `cms-admin/includes/gsc-api.php`
- `cms-admin/pages/gsc-settings.php`
- `cms-admin/actions/gsc-refresh.php`

**Diubah:**
- `cms-admin/includes/growth-agent-service.php` — tambah fungsi scan GSC
  (3 tipe, masing-masing menghitung `priority`) + refactor kecil supaya
  loop generate-nya reusable dari fungsi scan lama.
- `cms-admin/pages/growth-agent.php` — tambah card status GSC, 2 tombol
  baru, badge priority di tabel Recent jobs, panggilan
  `cms_gsc_fetch_if_stale()` (aditif, tidak menghapus apa pun).
- `cms-admin/includes/sidebar.php` — tambah link "GSC Settings" (kalau
  dipisah sebagai halaman sendiri sesuai § 2 — kemungkinan masuk grup "AI
  Management" bareng menu Growth Agent lain).
- `cms-admin/migrations/README.md` — entry baru untuk migrasi 015.
- `SITEMAP.md` — entry baru di Update Log begitu selesai (append-only,
  sesuai konvensi).

**Tidak diubah:** `services/GrowthAgentPromptBuilder.php`,
`cms-admin/pages/seo-recommendation-review.php` (keduanya reuse langsung,
0 baris berubah).

---

## 10. Urutan implementasi yang saya usulkan (kalau plan ini di-approve)

1. Migrasi 015 (2 tabel baru + kolom `priority`) + `gsc-api.php` (fetch
   pipeline) + `gsc-settings.php` — bisa dites end-to-end (connect service
   account → lihat data masuk ke `gsc_query_data`) sebelum lanjut ke
   bagian AI.
2. Tombol "Refresh Data" manual + `cms_gsc_fetch_if_stale()` di
   `growth-agent.php` — validasi pipeline fetch jalan dari UI & lazy
   trigger-nya, tanpa cron sama sekali.
3. Logic analisis 3 tipe (dengan `priority`) + integrasi prompt builder —
   Tipe 1 dulu (paling simpel karena reuse `seo-recommendation-review.php`
   100%), baru Tipe 2 & 3. Badge priority di UI ikut tahap ini.

Tiap tahap bisa direview terpisah kalau Anda mau, tidak harus satu commit
besar di akhir.

---

## Status keputusan

| # | Pertanyaan | Status |
|---|---|---|
| 1 | § 0.1 — cron | ✅ **Diputuskan: tanpa cron**, lazy-fetch 24 jam + lookback 14 hari |
| 2 | § 0.2 — dependency Composer vs REST manual | Rekomendasi **tanpa Composer** — belum ada penolakan eksplisit, saya anggap disetujui kecuali Anda bilang lain |
| 3 | § 5 — agent_key baru `growth_agent` vs reuse `seo_agent` untuk Tipe 2/3 | Masih terbuka |
| 4 | § 6 — 1 tombol "Scan GSC Opportunities" vs 3 tombol terpisah | Masih terbuka |
| 5 | § 1 (baru) — threshold HIGH/NORMAL priority di tabel § 1 | Usulan awal, boleh direvisi setelah lihat data real |

Kalau poin 3 & 4 tidak ada preferensi, saya akan jalan dengan rekomendasi
saya (agent_key baru `growth_agent`, 1 tombol scan gabungan) saat mulai
implementasi.
