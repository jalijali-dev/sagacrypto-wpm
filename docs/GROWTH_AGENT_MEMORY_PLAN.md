# Growth Agent — "Agent Memory" (rencana)

> **Status: SUDAH DIIMPLEMENTASI (19 Jul 2026), belum pernah dites end-to-end
> di server nyata** (butuh beberapa minggu data GSC historis terkumpul dulu
> sebelum pola apa pun realistis terdeteksi — lihat § 0). Semua 5 keputusan
> di bagian "Ringkasan keputusan" di bawah diimplementasikan persis seperti
> ditulis, tidak ada revisi selama coding. Lihat `SITEMAP.md` § Update Log
> 19 Jul 2026 untuk ringkasan implementasinya. Sumber yang dibaca sebelum
> menulis dokumen ini: `docs/GSC_INTEGRATION_PLAN.md`,
> `docs/GSC_OPPORTUNITIES_REVISION.md`, `cms-admin/includes/gsc-api.php`,
> `cms-admin/pages/growth-agent.php`, `services/GrowthAgentPromptBuilder.php`,
> `cms-admin/includes/growth-agent-service.php`.

Ditulis: 19 Juli 2026.

---

## 0. Konteks singkat — apa bedanya dengan "Prioritized Opportunities"

Gampang ketuker, jadi ditulis eksplisit dulu:

- **Prioritized Opportunities** (`gsc_opportunities`, sudah jalan) = daftar
  **aksi konkret saat ini** dari snapshot data GSC terbaru — "artikel X CTR-nya
  jelek, generate rekomendasi meta sekarang". Sekali di-generate, opportunity
  itu `actioned`, selesai perannya.
- **Agent Memory** (`growth_agent_memory`, rencana ini) = **pola yang
  bertahan dari waktu ke waktu**, dipakai sebagai *konteks latar belakang*
  supaya generate ide artikel baru berikutnya lebih terarah — bukan
  "lakukan ini sekarang", tapi "begini pola yang sudah terbukti berulang
  di data historis kita, pertimbangkan ini". Satu entry memory yang
  `active` tetap dipakai berkali-kali di banyak generate, sampai
  di-archive.

Keduanya baca dari tabel sumber yang sama (`gsc_query_data`), tapi
`gsc_opportunities` lihat snapshot terbaru, Memory lihat **rentang waktu
lebih panjang** (butuh histori beberapa minggu terkumpul dulu baru pola
"konsisten" kelihatan — di instalasi yang baru mulai jalan, jangan kaget
kalau awalnya nol/sedikit entry).

---

## 1. Skema — tabel baru `growth_agent_memory`, migrasi `017`

Nomor urut setelah `016_gsc_opportunities.sql`.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `insight_type` | ENUM('winning_pattern','content_gap') | Persis 2 jenis yang diminta — bukan dibuat lebih banyak kategori dari yang perlu. |
| `title` | VARCHAR(255) | Ringkasan satu baris, mis. *"Query seputar 'cara staking ETH' konsisten CTR tinggi"* |
| `description` | TEXT | Narasi lebih lengkap dengan angka — **template terparametrisasi, bukan AI-generated**, sama filosofi seperti `reason` di `gsc_opportunities` (murah, instan, tidak perlu token AI cuma buat menulis draft insight). |
| `supporting_data_json` | TEXT | Bukti mentah: query/artikel terkait, jumlah minggu muncul, avg CTR/posisi, total impressions — dipakai buat ditampilkan ke admin saat review, dan disimpan lagi di `active` entry (opsional, boleh dibaca ulang saat prompt-building, lihat § 4). |
| `status` | ENUM('pending_review','active','archived') DEFAULT 'pending_review' | |
| `reviewed_by` | INT UNSIGNED NULL | `admins.admin_id` |
| `reviewed_at` | TIMESTAMP NULL | |
| `detected_at` | TIMESTAMP | Kapan draft ini pertama kali dibuat. |
| `last_confirmed_at` | TIMESTAMP NULL | Di-bump tiap kali analisis ulang masih menemukan pola yang sama (lihat § 2 "upsert"). Dasar perhitungan retensi § 5 — bukan `detected_at`, supaya entry lama yang polanya MASIH bertahan tidak ikut ke-archive cuma karena tanggal dibuatnya sudah lama. |
| `dedupe_key` | CHAR(32) | `MD5(insight_type + '|' + query)` — subjek insight ini selalu 1 query spesifik (lihat § 2, tidak ada clustering topik/NLP — di luar scope). UNIQUE KEY, sama pola upsert seperti `gsc_opportunities`/`gsc_query_data`. |
| `created_at` | TIMESTAMP | |

**Kolom threshold baru** — bukan tabel baru, tapi kolom baru di
`gsc_settings` (singleton yang sudah ada): `memory_thresholds_json LONGTEXT`.
Sengaja **kolom terpisah** dari `opportunity_thresholds_json` yang sudah
ada (bukan dicampur ke situ) — nama kolom itu spesifik soal opportunity
scoring, mencampur threshold pattern-detection ke dalamnya bikin isinya
membingungkan untuk dibaca. Tetap satu tempat *per fitur*, konsisten
sama alasan permintaan "satu tempat" sebelumnya (supaya gampang di-tune,
bukan tersebar di kode) — cuma jadi dua blob JSON alih-alih satu, masing-
masing untuk fitur yang beda. Default nilai (di
`cms_gsc_default_memory_thresholds()`, pola sama seperti
`cms_gsc_default_opportunity_thresholds()`):

```php
[
    'min_distinct_weeks' => 3,        // pola harus muncul di >=3 minggu berbeda biar dianggap "konsisten", bukan spike sesaat
    'min_impressions' => 300,          // floor volume, sama filosofi seperti opportunity thresholds
    'winning_ctr_threshold' => 0.03,   // avg CTR >= 3% dianggap "winning"
    'winning_position_threshold' => 10.0, // ATAU avg posisi <= 10 dianggap "winning"
    'pending_review_stale_days' => 30, // § 5
    'active_stale_days' => 90,         // § 5
    'detection_interval_days' => 7,    // § 2 — seberapa sering auto-detect jalan
]
```

---

## 2. Logic deteksi — otomatis-jika-lewat-jadwal + tombol manual

**Rekomendasi saya: bukan tiap fetch, bukan murni manual — pola yang sama
seperti `cms_gsc_fetch_if_stale()`** (lazy-jika-lewat-interval + tombol
override), untuk alasan yang beda dari alasan fetch:

- **Kenapa bukan tiap fetch (beda dari `gsc_opportunities`):**
  `gsc_opportunities` murah dihitung ulang tiap fetch karena dia cuma
  lihat snapshot data TERBARU. Memory butuh `GROUP BY query` atas
  **seluruh window retensi** (`fetch_window_days`, default 90 hari) buat
  hitung "berapa minggu berbeda pola ini muncul" — query-nya lebih berat,//
  dan yang lebih penting: **hasilnya nyaris tidak berubah dari fetch ke
  fetch** (lazy-fetch bisa jalan tiap 24 jam kalau halaman sering dibuka,
  tapi "apakah query ini konsisten selama 3 minggu terakhir" secara alami
  cuma berubah pelan). Menjalankannya tiap fetch = kerja berulang buat
  hasil yang hampir selalu sama + berisiko bikin draft `pending_review`
  baru muncul terlalu sering (fatigue review buat admin).
- **Kenapa bukan murni manual:** biar tetap konsisten dengan filosofi
  "self-maintaining on request" yang sudah dipakai di seluruh Growth
  Agent (`cms_ensure_table()`, `cms_gsc_fetch_if_stale()`,
  `cms_growth_agent_cleanup_old_jobs()`) — kalau murni manual dan admin
  lupa klik, fitur ini diam selamanya.

**Desain:** `cms_growth_agent_detect_memory_if_stale($pdo, $maxAgeDays)`
dipanggil dari `growth-agent.php` (setelah `cms_gsc_fetch_if_stale()`),
baca `detection_interval_days` dari threshold (default 7 hari), cek kapan
terakhir analisis jalan (kolom baru `gsc_settings.last_memory_detection_at`,
nempel di baris singleton yang sama), kalau sudah lewat → jalankan.
Tombol manual **"Analisis Pola"** tetap ada di panel Agent Memory buat
override kapan saja (mis. setelah admin sengaja nunggu data numpuk lalu
mau cek sekarang).

**Query deteksi** (dua-duanya baca `gsc_query_data` full window, tidak
butuh tabel/kolom baru di sana):

**`winning_pattern`:**
```sql
SELECT query,
       COUNT(DISTINCT YEARWEEK(data_date)) AS distinct_weeks,
       AVG(ctr) AS avg_ctr,
       AVG(position) AS avg_position,
       SUM(impressions) AS total_impressions,
       MAX(matched_page_id) AS matched_page_id
  FROM gsc_query_data
 GROUP BY query
HAVING distinct_weeks >= :min_distinct_weeks
   AND total_impressions >= :min_impressions
   AND (avg_ctr >= :winning_ctr_threshold OR avg_position <= :winning_position_threshold)
```

**`content_gap`:**
```sql
SELECT query,
       COUNT(DISTINCT YEARWEEK(data_date)) AS distinct_weeks,
       SUM(impressions) AS total_impressions,
       AVG(position) AS avg_position
  FROM gsc_query_data
 GROUP BY query
HAVING distinct_weeks >= :min_distinct_weeks
   AND total_impressions >= :min_impressions
   AND SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0
```

**Upsert per hasil** (dedupe_key = `insight_type` + `query`):
- Belum ada baris dengan `dedupe_key` itu → INSERT baru, `status='pending_review'`.
- Sudah ada & `status IN ('pending_review','active')` → UPDATE
  `description`/`supporting_data_json`/`last_confirmed_at` (angka
  ter-refresh, tapi **status tidak direset** — entry yang sudah `active`
  tetap `active`, tidak dipaksa balik ke `pending_review` cuma karena
  masih valid; entry `pending_review` yang belum sempat direview juga
  tetap nunggu, cuma datanya makin update).
- Sudah ada & `status = 'archived'` → **dilewati, tidak dibuat ulang**.
  Baik yang di-reject admin maupun yang habis masa retensinya (§ 5)
  sengaja tidak otomatis muncul lagi — mencegah insight yang sudah
  ditolak/basi nongol berulang-ulang. (Kalau nanti butuh cara admin
  "un-reject" atau reset manual, itu penambahan kecil belakangan, di
  luar scope MVP ini.)

Tidak ada clustering topik/NLP (mis. gabungin "harga bitcoin hari ini" +
"btc price today" jadi satu topik) — tiap entry itu 1 query spesifik,
sama granularitas seperti fitur lain di Growth Agent sejauh ini. Kalau
nanti kerasa kurang (query yang mirip-mirip jadi entry terpisah-pisah),
itu peningkatan terpisah, bukan bagian MVP ini.

---

## 3. Review UI — panel inline di `growth-agent.php` (bukan halaman terpisah)

Beda dari **GSC Settings** (halaman terpisah, karena beda role-tier —
nyimpen kredensial mentah, superadmin-only), Agent Memory **tidak nyimpen
apa pun yang sensitif**, jadi ikut tier admin+ yang sama seperti
`growth-agent.php` — konsisten dengan pola **"Style rules"** yang sudah
ada di halaman itu (juga koleksi knowledge terkurasi, direview manual,
dipakai sebagai prompt context).

**Panel baru "Agent Memory"**, taruh dekat panel "Style rules" (sama-sama
"konteks yang di-fold ke prompt"):
- Sub-bagian **"Pending Review"** (tampil duluan/menonjol kalau ada) —
  tabel: Type (`winning_pattern`/`content_gap`, badge beda warna) | Title
  | Evidence (ringkasan angka dari `supporting_data_json` — query,
  distinct weeks, avg CTR/posisi, total impressions, ditampilkan langsung
  di baris tabel, **bukan modal/expand terpisah** — cukup ringkas buat
  muat, konsisten dengan pola kolom "Reason" di tabel Prioritized
  Opportunities) | tombol Approve/Reject.
- Sub-bagian **"Active"** — daftar ringkas entry yang sedang dipakai AI,
  read-only + tombol "Archive" manual kalau admin mau menonaktifkan
  sebelum waktunya (tanpa nunggu retensi otomatis).
- Tombol **"Analisis Pola"** di panel head (lihat § 2).
- Entry `archived` **tidak ditampilkan** di panel ini (tidak perlu UI
  baca-arsip untuk MVP — bisa query manual ke DB kalau admin perlu audit,
  sama seperti error log yang juga tidak semuanya diekspos di UI).

---

## 4. Integrasi ke `GrowthAgentPromptBuilder` — 0 perubahan di titik panggil

Poin penting: `cms_growth_agent_generate_article_idea()` (satu-satunya
fungsi terkait `recommended_action = gsc_article_idea`) **sudah**
memanggil persis:

```php
$growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_article_idea'));
```

`buildContext($agentKey, $jobType, ...)` **sudah menerima `$jobType`
sebagai parameter** — jadi Memory bisa disisipkan dengan extend method
ini secara internal (cek `if ($jobType === 'gsc_article_idea')`), **tanpa
mengubah baris panggilan di manapun**. `cms_growth_agent_generate_content_optimization()`
dan `cms_growth_agent_run_seo_recommendation_scan()` memanggil
`buildContext()` dengan `$jobType` yang beda (`'gsc_content_optimization'`,
`'seo_recommendation'`) — otomatis tidak kena imbas, sesuai keputusan #3.

Perubahan di dalam `GrowthAgentPromptBuilder::buildContext()`: tambah satu
private method baru `activeMemoryEntries(int $limit = 8): array` (pola
sama seperti `activeStyleRules()`/`approvedExamples()` yang sudah ada di
class ini), dipanggil HANYA saat `$jobType === 'gsc_article_idea'`, hasil
di-fold ke `$parts[]` yang sama seperti style rules & few-shot examples
sekarang (satu blok teks tambahan, bukan mekanisme baru):

```
Known patterns from historical search data (context, not literal instructions):
- [winning_pattern] Query seputar "cara staking ETH" konsisten CTR tinggi (avg CTR 4.2%, muncul di 6 minggu berbeda, total 3400 impressions)
- [content_gap] Query "harga bitcoin vs ethereum 2026" berulang muncul (4 minggu, 900 impressions) tapi belum ada artikel yang membahasnya
```

Diambil dari `title` + angka ringkas dari `supporting_data_json`
(bukan dump JSON mentah ke prompt) — `WHERE status = 'active' ORDER BY
last_confirmed_at DESC LIMIT :limit`, tanpa filter relevansi ke query
spesifik yang sedang digenerate (sama seperti Style Rules sekarang:
semua entry active ikut, tidak ada retrieval/embedding — dicatat sebagai
peningkatan potensial nanti kalau corpus-nya sudah besar, sama disclaimer
yang sudah ada di docblock class ini soal few-shot examples).

---

## 5. Retensi — dua ambang beda untuk `pending_review` vs `active`

Dari `memory_thresholds_json` (§ 1):

- **`pending_review` yang tidak direview dalam `pending_review_stale_days`
  (default 30 hari)** sejak `detected_at` → **auto-archive**. Alasannya:
  draft yang dibiarkan menumpuk tanpa keputusan admin cuma jadi noise;
  kalau memang masih relevan, deteksi berikutnya akan bikin dedupe_key
  yang SAMA — tapi karena sudah `archived`, tidak dibuat ulang (§ 2)
  **kecuali** kita longgarkan aturan itu khusus untuk kasus
  auto-archive-karena-tidak-direview (beda dari reject eksplisit admin).
  **Keputusan yang saya ambil:** ya, dibedakan — auto-archived (karena
  kelewat, bukan ditolak) BOLEH dibuat ulang sebagai `pending_review` baru
  di deteksi berikutnya kalau pola masih ada; reject eksplisit admin
  (klik "Reject") permanen menekan dedupe_key itu. Perlu kolom tambahan
  kecil `archived_reason ENUM('rejected','stale_pending','stale_active')`
  buat membedakan ini saat upsert re-check di § 2.
- **`active` yang `last_confirmed_at`-nya lebih tua dari
  `active_stale_days` (default 90 hari)** → auto-archive
  (`archived_reason='stale_active'`) — pola itu sudah tidak muncul lagi
  di deteksi terbaru manapun (kalau masih muncul, `last_confirmed_at`
  akan ke-bump terus, tidak pernah jadi stale). Entry ini otomatis
  berhenti ikut ke-fold ke prompt (§ 4 cuma ambil `status='active'`).
- Dijalankan sebagai bagian dari `cms_growth_agent_detect_memory_if_stale()`
  yang sama (sekali jalan, urus generate draft baru + archive yang basi),
  plus ikut tombol manual "Analisis Pola".
- **Tidak pernah delete** — cuma archive, konsisten dengan
  `cms_growth_agent_cleanup_old_jobs()` yang juga tidak pernah menghapus
  `manual_action`/hal yang masih relevan; bedanya di sini "archive" bukan
  "delete", karena tabel ini kecil (grouped per query, bukan per-fetch
  row) jadi tidak ada masalah pertumbuhan tak terbatas yang perlu di-DELETE.

---

## 6. Manifest file (rencana)

**Baru:**
- `cms-admin/migrations/017_growth_agent_memory.sql` — tabel
  `growth_agent_memory`, `ALTER gsc_settings ADD COLUMN
  memory_thresholds_json`, `ADD COLUMN last_memory_detection_at`. Pakai
  pola stored-procedure-guard untuk tiap `ADD COLUMN` (bukan
  `ADD COLUMN IF NOT EXISTS`) — pelajaran dari migrasi 015/016 yang
  gagal di production karena versi MySQL/MariaDB tidak mendukung syntax
  itu, lihat `migrations/README.md`.

**Diubah:**
- `cms-admin/includes/gsc-api.php` — tambah
  `cms_gsc_default_memory_thresholds()`,
  `cms_gsc_get_memory_thresholds($pdo)` (pola identik fungsi opportunity
  thresholds yang sudah ada), `cms_growth_agent_detect_memory_if_stale()`
  (atau ditaruh di `growth-agent-service.php` — lebih pas di situ karena
  fungsinya soal `growth_agent_memory`, bukan soal koneksi/fetch GSC;
  akan dipastikan penempatannya konsisten pola existing saat
  implementasi).
- `cms-admin/includes/growth-agent-service.php` — fungsi deteksi pola
  (§ 2), fungsi cleanup/retensi (§ 5), action handler approve/reject/archive
  manual dipanggil dari `growth-agent.php`.
- `cms-admin/pages/growth-agent.php` — panel "Agent Memory" (§ 3),
  panggilan `cms_growth_agent_detect_memory_if_stale()` di awal file
  (sebaris dengan `cms_gsc_fetch_if_stale()` yang sudah ada).
- `services/GrowthAgentPromptBuilder.php` — method baru
  `activeMemoryEntries()`, `buildContext()` extended (§ 4) — **satu-
  satunya file dari fase GSC sebelumnya yang akhirnya berubah**; di dua
  fase sebelumnya file ini selalu "0 baris berubah", di fase ini memang
  perlu disentuh karena memang di situ tempat yang benar untuk fold
  context baru.
- `cms-admin/migrations/README.md` — entry `017`.
- `SITEMAP.md` — entry baru di Update Log begitu implementasi selesai.

**Tidak berubah:** `seo-recommendation-review.php`,
`cms_growth_agent_run_seo_recommendation_scan()`,
`cms_growth_agent_generate_content_optimization()`,
`cms_gsc_compute_opportunities()` — semuanya di luar jalur
`gsc_article_idea`, sesuai keputusan #3.

---

## Ringkasan keputusan yang saya ambil (boleh di-override)

1. **Trigger deteksi:** otomatis-jika-lewat-jadwal (default 7 hari) +
   tombol manual "Analisis Pola" — bukan tiap fetch (terlalu sering,
   hasilnya nyaris tidak berubah + bikin fatigue review), bukan murni
   manual (tidak konsisten dengan filosofi self-maintaining fitur lain).
2. **Threshold Memory** di kolom JSON **terpisah**
   (`memory_thresholds_json`) dari `opportunity_thresholds_json` yang
   sudah ada — tetap "satu tempat", tapi satu tempat per fitur, bukan
   dicampur jadi satu blob raksasa yang isinya campur aduk dua konsep.
3. **Auto-archive dibedakan dari reject eksplisit** (`archived_reason`) —
   auto-archive-karena-kelewat boleh muncul lagi di deteksi berikutnya
   kalau polanya masih ada; reject eksplisit admin permanen menekan
   dedupe_key itu.
4. **Tidak ada clustering topik/NLP** — granularitas per-query persis,
   sama seperti fitur lain di Growth Agent. Peningkatan terpisah kalau
   nanti dibutuhkan.
5. **Panel inline di `growth-agent.php`**, bukan halaman terpisah — beda
   dari GSC Settings (yang perlu terpisah karena kredensial +
   superadmin-only), Memory tidak nyimpen apa pun sensitif, jadi ikut
   pola "Style rules" yang sudah ada di halaman yang sama.

Silakan review — kalau ada yang mau diubah dari 5 poin di atas, atau
detail threshold di § 1, tinggal bilang sebelum saya mulai implementasi.
