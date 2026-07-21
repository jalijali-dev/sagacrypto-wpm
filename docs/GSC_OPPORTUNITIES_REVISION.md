# Growth Agent × GSC — Revisi: Prioritized Opportunities

> **Status: SUDAH DIIMPLEMENTASI (18 Jul 2026), belum pernah dites end-to-end
> di server nyata.** Revisi atas `docs/GSC_INTEGRATION_PLAN.md` (v1, juga
> sudah diimplementasikan sebelumnya) — dokumen v1 dibiarkan apa adanya
> sebagai catatan historis. Ke-4 pertanyaan di bagian bawah dokumen ini
> sudah dijawab user dan diimplementasikan persis sesuai jawabannya: (1)
> recompute otomatis tiap fetch + tombol manual, (2) tombol bulk v1 dihapus
> total diganti tabel ini, (3) `growth_agent_jobs.priority` dilebarkan jadi
> 3-tier low/medium/high, (4) semua threshold di satu tempat
> (`gsc_settings.opportunity_thresholds_json`, lihat
> `cms_gsc_default_opportunity_thresholds()` di `gsc-api.php`). Lihat
> `SITEMAP.md` § Update Log 18 Jul 2026 untuk ringkasan implementasinya.
> **Belum divalidasi dengan data GSC sungguhan.**

Ditulis: 18 Juli 2026.

---

## 0. Apa yang berubah dari v1, dan kenapa

**v1 (sudah jalan):** tombol "Scan GSC Opportunities" langsung memanggil AI
untuk sampai 5 kandidat per tipe (CTR/content/article idea) sekaligus, hasil
generate-nya langsung jadi `growth_agent_jobs` row (job_type
`seo_recommendation`/`gsc_content_optimization`/`gsc_article_idea`). Admin
tidak punya visibilitas ke *kenapa* suatu kandidat dipilih sebelum AI-nya
jalan — scoring/keputusan "mana yang layak digarap" ada di dalam SQL
`HAVING` clause yang tidak terlihat di UI.

**v2 (revisi ini):** dipisah jadi 2 tahap yang jelas:
1. **"Prioritized Opportunities"** — tabel yang murni **hasil hitungan dari
   data GSC, TANPA panggil AI sama sekali** (Impact, Effort, Priority,
   Reason semuanya formula/template, bukan AI generation). Ini yang
   membuat tabelnya bisa langsung tampil lengkap begitu data GSC ada, tanpa
   nunggu/bayar token AI, dan admin bisa lihat *alasan* tiap item sebelum
   memutuskan mana yang mau digarap.
2. **Generate on-demand** — admin klik satu item spesifik dari tabel itu →
   baru di titik ini AI dipanggil (reuse 100% logic generate yang sudah
   dibangun di v1, cuma dipicu per-item bukan bulk 5-sekaligus) → hasil
   masuk ke `seo-recommendation-review.php` atau antrian Approve/Reject
   generik seperti biasa.

Tombol bulk "Scan GSC Opportunities" (v1) **digantikan** oleh tabel ini —
tidak ada lagi auto-generate 5 kandidat tanpa kurasi manusia dulu. Fungsi
generate yang sudah dibangun di v1
(`cms_growth_agent_run_seo_recommendation_scan()`,
`cms_growth_agent_scan_gsc_content_optimization()`,
`cms_growth_agent_scan_gsc_article_ideas()`) **tetap dipakai**, cuma
dipanggil untuk 1 item spesifik (hasil klik admin) bukan bulk — lihat § 5.

---

## 1. Skema baru — tabel `gsc_opportunities`

Migrasi baru (`016`, setelah `015`). Dihitung ulang otomatis setiap kali
`cms_gsc_fetch_and_cache()` selesai narik data baru (murah — pure SQL,
tidak ada biaya AI), plus tombol manual "Recompute Opportunities" buat
refresh on-demand tanpa nunggu fetch berikutnya.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `item_type` | ENUM('page','query') | Lihat § 3 — dibedakan dari data, bukan dipilih manual |
| `matched_page_id` | INT UNSIGNED NULL | Diisi kalau `item_type='page'` |
| `query_text` | VARCHAR(255) NULL | Diisi kalau `item_type='query'` |
| `matched_categories` | VARCHAR(255) | CSV tag pendek: `Low CTR`, `Zero-click`, `Page-one`, `No article` (bisa lebih dari satu, lihat § 3) |
| `impact_score` | TINYINT UNSIGNED | 1-10, lihat § 2 |
| `effort_score` | TINYINT UNSIGNED | 1-10, lihat § 2 |
| `priority` | ENUM('low','medium','high') | Diturunkan dari Impact+Effort, lihat § 2.3 |
| `recommended_agent` | VARCHAR(50) | `seo_agent` atau `growth_agent` — dari mana rekomendasi lengkap akan digenerate |
| `recommended_action` | ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea') | **Tambahan di luar yang eksplisit diminta** — dipakai buat dispatch programatik yang reliable di § 5, bukan parsing ulang `matched_categories` yang formatnya bebas |
| `reason` | TEXT | Narasi dengan angka konkret, lihat § 2.4 |
| `metrics_json` | TEXT | Snapshot angka mentah (impressions, clicks, ctr, position, dst) dipakai ulang saat generate — supaya tidak query ulang gsc_query_data |
| `status` | ENUM('open','actioned') DEFAULT 'open' | `actioned` begitu admin klik Generate |
| `linked_job_id` | INT UNSIGNED NULL | Diisi begitu ter-generate — UI ganti tombol "Generate" jadi "View" |
| `dedupe_key` | CHAR(32) | `MD5(item_type + '|' + matched_page_id/query_text)` — UNIQUE KEY, upsert idempotent tiap recompute (skor bisa berubah, tapi baris yang sama tidak dobel) |
| `computed_at` | TIMESTAMP | |

**Catatan penting:** recompute tidak menyentuh baris yang sudah
`status='actioned'` (dedupe upsert cuma update skor/reason untuk baris
`'open'` — sekali di-generate, baris itu "beku" sebagai catatan histori apa
yang pernah direkomendasikan, tidak ikut berubah kalau datanya bergeser
lagi minggu depan).

---

## 2. Formula scoring — dirancang supaya bisa dijelaskan, bukan black-box

### 2.1 Impact (1-10)

Dua komponen di-rata-rata, dibulatkan: `impact = round((volume_bucket + signal_bucket) / 2)`.

**Volume bucket** (sama untuk semua kategori — makin besar impresi, makin besar dampak potensialnya):

| Total impressions (window fetch) | Skor |
|---|---|
| ≥ 5000 | 10 |
| 2000–4999 | 8 |
| 1000–1999 | 6 |
| 500–999 | 4 |
| 200–499 | 2 |
| < 200 | 1 |

**Signal bucket** — tergantung kategori mana yang match (kalau item match lebih dari satu kategori, pakai yang skornya PALING TINGGI):

- **Low CTR** — gap antara CTR item ini vs rata-rata CTR site **di rentang posisi yang sama** (bukan rata-rata flat semua posisi — supaya adil, artikel posisi 3 dan posisi 18 memang wajar beda CTR-nya). Rentang posisi: 1-3, 4-10, 11-20, 21+, dihitung ulang tiap fetch dari seluruh `gsc_query_data`.

  | Gap (avg CTR bucket − CTR item ini) | Skor |
  |---|---|
  | ≥ 5 poin persen | 10 |
  | 3–4.9 | 7 |
  | 1.5–2.9 | 5 |
  | 0.5–1.4 | 3 |
  | < 0.5 | 1 |

- **Zero-click** — clicks = 0 (atau ≤1% dari impressions) walau impresi lolos floor. Skor tetap (bukan gradasi) = **9** — secara definisi ini kegagalan konversi total, selalu dianggap signifikan kalau lolos floor volume.

- **Page-one** (posisi 11–20, "dekat" page-one tapi belum masuk) — makin dekat ke posisi 10, makin tinggi:

  | Avg position | Skor |
  |---|---|
  | 11–13 | 10 |
  | 14–16 | 7 |
  | 17–20 | 4 |

- **No article** (query grup, tidak ada artikel sama sekali) — tidak ada sinyal CTR/posisi yang relevan (tidak ada yang di-rank), jadi signal bucket = volume bucket itu sendiri (impact = volume bucket, bukan rata-rata dengan komponen lain).

### 2.2 Effort (1-10)

Effort itu soal **jenis pekerjaan**, bukan besaran angka GSC — jadi lookup tetap per kategori, bukan formula:

| Kategori (aksi yang dibutuhkan) | Effort |
|---|---|
| Low CTR saja (ganti title/meta description) | 2 |
| Zero-click pada page yang sudah ada (title/meta + intro perlu dipertajam) | 4 |
| Page-one / striking distance (perlu tambah section/perdalam konten) | 6 |
| No article (artikel baru dari nol) | 9 |

Kalau item match lebih dari satu kategori, **Effort = MAKSIMUM** dari kategori yang match (asumsi kerja terberat, bukan termudah — supaya tidak under-estimate).

### 2.3 Priority (High / Medium / Low)

Matriks impact/effort standar, threshold eksplisit:

- **High** — `impact ≥ 7 AND effort ≤ 5` (dampak besar, kerjaan murah — quick win), ATAU `impact ≥ 9` berapa pun effort-nya (terlalu signifikan buat diabaikan).
- **Low** — `impact ≤ 3`, ATAU (`impact ≤ 5 AND effort ≥ 8`) (dampak kecil, atau dampak sedang tapi kerjaan berat — ROI rendah).
- **Medium** — sisanya.

### 2.4 Reason — template terparametrisasi, BUKAN AI

Supaya "menyebutkan angka konkret" tapi tidak perlu panggil AI cuma buat menulis satu kalimat (mahal & lambat kalau dilakukan untuk puluhan baris tiap render), Reason dirangkai dari template per kategori dengan angka asli disisipkan langsung dari `metrics_json`. Contoh (bukan final copy, ilustrasi struktur):

- **Low CTR**: *"Artikel ini dapat {impressions} impressions dalam {lookback_days} hari terakhir tapi CTR cuma {ctr}% — jauh di bawah rata-rata {bucket_avg_ctr}% untuk posisi {position_bucket_label}. Kemungkinan title/meta description kurang menarik klik. Saran: tulis ulang title yang lebih spesifik & meta description dengan angka/CTA."*
- **Zero-click**: *"Query '{query}' muncul {impressions} kali tapi belum pernah diklik sama sekali, di posisi rata-rata {position}. Saran: cek relevansi title terhadap query ini, pertimbangkan tambah section yang eksplisit menjawabnya."*
- **Page-one**: *"Artikel ini nangkring di posisi rata-rata {position} untuk query '{top_query}' ({impressions} impressions) — dekat masuk page satu. Saran: perdalam bagian yang relevan dengan query ini, tambah subheading yang eksplisit menyebut kata kuncinya."*
- **No article**: *"Query '{query}' mendapat {impressions} impressions tapi situs belum punya artikel yang membahasnya sama sekali. Saran: buat artikel baru menargetkan kata kunci ini."*

Kalau nanti dirasa template ini kurang "pintar" dibanding narasi AI, itu upgrade terpisah (AI dipanggil pas compute, bukan pas generate) — sengaja tidak dilakukan di revisi ini supaya listing tetap instan & gratis.

---

## 3. Membedakan item "page" vs "query"

Logic ini **reuse langsung** dari v1 (Tipe 1/2 sudah page-based via
`matched_page_id`, Tipe 3 sudah query-based dengan syarat "semua baris
query itu `matched_page_id IS NULL`") — tidak ada perubahan konsep, cuma
disatukan ke satu tabel `gsc_opportunities` alih-alih 3 fungsi scan
terpisah yang langsung generate:

- **`item_type='page'`**: agregasi per `matched_page_id` (join ke `pages`,
  `status='published'`). Kategori yang mungkin match: `Low CTR`,
  `Zero-click`, `Page-one` (kalau avg position kebetulan 11-20 juga).
- **`item_type='query'`**: agregasi per `query`, HANYA kalau **semua**
  baris `gsc_query_data` untuk query itu punya `matched_page_id IS NULL`
  (kalau sebagian match sebagian tidak, query itu dianggap "sudah
  ke-cover" oleh page yang match, tidak dianggap `No article`). Kategori
  yang mungkin match: `No article` (selalu), plus `Zero-click`/`Page-one`
  kalau relevan.

---

## 4. Panel "Google Search Console" (aggregate stats + Top Queries)

Tambahan di atas panel status yang sudah ada di v1 (last fetch/property),
bukan pengganti:

- **Date range** — label eksplisit, mis. "28 hari terakhir (per {end_date})
  — data GSC punya delay ~3 hari, jadi 3 hari paling baru belum termasuk."
  Dihitung dari `fetch_lookback_days` settings + catatan lag statis.
- **Aggregate**: `SUM(clicks)`, `SUM(impressions)`, CTR keseluruhan
  (`SUM(clicks)/SUM(impressions)`), rata-rata posisi (`AVG(position)`
  ter-bobot impressions — bukan average polos, supaya query kecil tidak
  menyeret rata-rata sama beratnya dengan query besar) — semua dari
  `gsc_query_data` dalam window yang sama.
- **Top Queries** — 10 query dengan impressions tertinggi, kolom query/
  clicks/impressions/ctr/position — query langsung dari `gsc_query_data`
  `GROUP BY query ORDER BY SUM(impressions) DESC LIMIT 10`, tidak perlu
  tabel baru.

---

## 5. Generate on-demand → job pipeline yang sudah ada (v1)

Tombol "Generate" per baris di tabel Prioritized Opportunities → POST
action baru `generate_from_opportunity` (di `growth-agent.php`, atau
action file terpisah kalau lebih rapi) dengan `opportunity_id`:

1. Load baris `gsc_opportunities`.
2. Dispatch berdasarkan `recommended_action` (kolom baru, bukan parsing
   `matched_categories`):
   - `seo_recommendation` → panggil ulang
     `cms_growth_agent_run_seo_recommendation_scan($pdo, [$pageRow])` —
     fungsi ini **sudah generic terhadap jumlah page** (nerima array),
     tinggal kirim array isi 1 page hasil load dari opportunity. Hasilnya
     tetap `job_type='seo_recommendation'`, tetap masuk
     `seo-recommendation-review.php` **tanpa perubahan apa pun** di situ
     (persis seperti klaim v1).
   - `gsc_content_optimization` / `gsc_article_idea` → butuh sedikit
     refactor kecil: `cms_growth_agent_scan_gsc_content_optimization()`
     dan `cms_growth_agent_scan_gsc_article_ideas()` di v1 masing-masing
     melakukan **candidate-selection (SQL) + generate** dalam satu fungsi
     (pola lama, sebelum ada tabel opportunities). Untuk dipanggil
     per-item, dipecah sama seperti Tipe 1 sudah dipecah di v1: candidate
     data sudah ada di `gsc_opportunities.metrics_json` (tidak perlu
     query ulang), jadi cukup ekstrak bagian generate-nya jadi fungsi
     kecil `cms_growth_agent_generate_content_optimization($pdo, array $page, string $priority)`
     dan `cms_growth_agent_generate_article_idea($pdo, array $queryData, string $priority)`
     — dipanggil baik dari sini maupun (kalau masih dipakai) alur lama.
3. Set `gsc_opportunities.status='actioned'`, `linked_job_id=<id baru>`.
4. Redirect balik dengan flash message + tabel opportunities menampilkan
   baris itu sebagai "Actioned" dengan link "Review" (bukan lagi tombol
   Generate).

**`priority` yang dikirim ke `cms_growth_agent_log_job()`** dipetakan dari
`gsc_opportunities.priority` (`high`/`medium`/`low`) — tapi kolom
`growth_agent_jobs.priority` di v1 cuma `ENUM('normal','high')` (2 tingkat,
sesuai permintaan awal). Perlu diputuskan (§ Pertanyaan di bawah): lebar-in
enum itu jadi 3 tingkat juga, atau map `medium`→`normal` saat masuk ke
`growth_agent_jobs` (badge di Recent Jobs tetap cuma nunjukin HIGH seperti
sekarang, MEDIUM dari opportunity tidak ikut ditandai di situ).

---

## 6. Yang berubah dari kode v1 yang sudah ada

| File | Perubahan |
|---|---|
| `cms-admin/migrations/016_gsc_opportunities.sql` (baru) | Tabel `gsc_opportunities` |
| `cms-admin/includes/gsc-api.php` | Tambah `cms_gsc_compute_opportunities($pdo)` — dipanggil di akhir `cms_gsc_fetch_and_cache()` |
| `cms-admin/includes/growth-agent-service.php` | Pecah `cms_growth_agent_scan_gsc_content_optimization()`/`cms_growth_agent_scan_gsc_article_ideas()` jadi versi single-item (dipakai dispatch § 5); `cms_growth_agent_scan_gsc_ctr_opportunities()` sudah generic, tinggal dipanggil dengan 1 item |
| `cms-admin/pages/growth-agent.php` | **Hapus** tombol bulk "Scan GSC Opportunities"; **tambah** panel aggregate stats + Top Queries (§ 4) dan tabel Prioritized Opportunities (§ 1) dengan tombol Generate per baris; action handler baru `generate_from_opportunity` |

**Tidak berubah:** `seo-recommendation-review.php`,
`GrowthAgentPromptBuilder.php`, alur AI-call/parse/log (`cms_ai_resolve_agent`,
`cms_ai_call_provider`) — semua di v1 tetap reused apa adanya.

---

## Pertanyaan yang butuh jawaban Anda sebelum saya mulai coding

1. **Recompute cadence** — otomatis tiap `cms_gsc_fetch_and_cache()` selesai
   (rekomendasi saya, murah karena tanpa AI) + tombol manual tambahan, atau
   manual saja?
2. **Tombol bulk v1 ("Scan GSC Opportunities")** — dihapus total dan
   digantikan tabel ini (rekomendasi saya, sesuai referensi UI Anda), atau
   tetap dipertahankan berdampingan sebagai jalur cepat?
3. **`growth_agent_jobs.priority`** — lebarkan enum jadi
   `'low','normal','medium','high'` (4 nilai, technically breaking dari v1
   yang cuma 2) supaya konsisten 1:1 dengan opportunity, atau map
   `low`/`medium` opportunity → `normal` job (badge Recent Jobs tetap
   biner seperti sekarang)? Saya condong ke **map ke `normal`** — badge di
   Recent Jobs jadi terlalu ramai kalau 4 warna, dan High/Medium/Low itu
   konsepnya milik tabel Opportunities, bukan job.
4. **Threshold angka di § 2** (volume bucket, gap CTR, dst.) — semua nilai
   awal, belum divalidasi ke data GSC sungguhan (belum ada koneksi live).
   Oke jalan dengan angka ini dulu lalu disesuaikan setelah lihat data
   asli, atau ada angka spesifik dari referensi Anda yang harus saya ikuti
   persis?
