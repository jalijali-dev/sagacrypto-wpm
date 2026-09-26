<?php
declare(strict_types=1);

/**
 * SagaCrypto — Kebijakan Privasi. Static legal page, no DB writes.
 * Added ahead of the Android (APK) release so the app listing has a
 * privacy-policy URL to point to. Content mirrors the sibling Sagagoal
 * page's structure (see https://sagagoal.com/kebijakan-privasi) but
 * describes what THIS site actually does — no games, no push
 * notifications yet, contact form protected by Cloudflare Turnstile.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$pageTitle = 'Kebijakan Privasi — SagaCrypto';
$pageDescription = 'Kebijakan privasi SagaCrypto: informasi apa yang kami kumpulkan, bagaimana digunakan, dan hak Anda.';
$activeNav = '';
$canonicalUrl = wpm_site_url('kebijakan-privasi');
$wpmLastUpdated = '26 September 2026';

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Kebijakan Privasi</nav>
        <span class="section-kicker">Legal</span>
        <h1>Kebijakan Privasi</h1>
        <p>Terakhir diperbarui: <?= wpm_esc($wpmLastUpdated) ?></p>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <div class="article-prose" style="max-width:820px;">

            <p>SagaCrypto ("kami") mengoperasikan situs web SagaCrypto.com beserta aplikasi Android-nya (selanjutnya disebut "Layanan"), yang menyajikan berita crypto, harga pasar real-time, dan analisis market. Kebijakan Privasi ini menjelaskan informasi apa saja yang kami kumpulkan dari pengunjung/pengguna Layanan, bagaimana informasi itu digunakan, dan pilihan yang Anda miliki.</p>

            <p>Dengan menggunakan Layanan ini, Anda menyetujui pengumpulan dan penggunaan informasi sesuai kebijakan ini.</p>

            <h2>1. Informasi yang Kami Kumpulkan</h2>

            <h3>a. Informasi yang Anda berikan langsung</h3>
            <p>Jika Anda mengisi formulir Kontak di Layanan, kami menyimpan nama, alamat email, subjek, dan isi pesan Anda ke dalam basis data kami sendiri, semata-mata untuk membalas dan menindaklanjuti pertanyaan/masukan Anda. Data ini tidak dibagikan ke pihak ketiga mana pun.</p>

            <h3>b. Verifikasi anti-spam</h3>
            <p>Formulir Kontak dilindungi Cloudflare Turnstile untuk mencegah pesan spam otomatis. Turnstile memproses sinyal teknis dari browser/perangkat Anda (bukan isi pesan Anda) sesuai <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">Kebijakan Privasi Cloudflare</a>.</p>

            <h3>c. Data analitik otomatis</h3>
            <p>Kami menggunakan Google Analytics (GA4) untuk memahami bagaimana Layanan digunakan secara agregat — misalnya halaman yang paling banyak dikunjungi, perkiraan lokasi umum (negara/kota), jenis perangkat, dan durasi kunjungan. Data ini dikumpulkan oleh Google sesuai <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Kebijakan Privasi Google</a> dan tidak kami gunakan untuk mengidentifikasi Anda secara pribadi.</p>

            <h3>d. Notifikasi push (aplikasi Android)</h3>
            <p>Jika Anda mengaktifkan notifikasi di aplikasi Android kami (mis. untuk update harga atau berita breaking), perangkat Anda akan meminta izin notifikasi. Setelah diizinkan, kami menyimpan token perangkat yang dihasilkan lewat layanan Firebase Cloud Messaging (milik Google) beserta jenis perangkat Anda. Token ini bersifat anonim — tidak terhubung ke nama, email, atau identitas pribadi Anda — dan hanya digunakan untuk mengirimkan notifikasi yang Anda pilih untuk terima. Anda dapat menonaktifkan notifikasi kapan saja lewat pengaturan aplikasi/perangkat Anda.</p>

            <h3>e. Preferensi lokal</h3>
            <p>Layanan dapat menyimpan beberapa preferensi teknis langsung di perangkat Anda (localStorage browser), bukan di server kami, seperti status pop-up iklan yang sudah Anda tutup. Data ini tidak pernah meninggalkan perangkat Anda dan bisa dihapus kapan saja lewat pengaturan browser.</p>

            <h3>f. Data harga &amp; berita crypto</h3>
            <p>Harga, kapitalisasi pasar, dan data market yang ditampilkan di Layanan berasal dari penyedia data pihak ketiga (CoinGecko API). Ini murni data pasar publik dan sama sekali tidak melibatkan data pribadi pengunjung Layanan.</p>

            <h2>2. Cookie dan Teknologi Serupa</h2>
            <p>Layanan menggunakan cookie sesi teknis dasar (untuk fungsi situs) serta cookie/identifier yang ditempatkan oleh Google Analytics dan Cloudflare Turnstile untuk keperluan analitik dan anti-spam sebagaimana dijelaskan di atas. Anda dapat mengatur browser Anda untuk menolak cookie, meskipun beberapa fitur Layanan mungkin tidak berfungsi optimal tanpanya.</p>

            <h2>3. Iklan</h2>
            <p>Layanan dapat menampilkan iklan (banner gambar, teks, video, atau kode HTML) yang dikelola melalui panel admin kami. Sebagian slot iklan dapat berisi kode dari jaringan iklan pihak ketiga; jaringan tersebut dapat mengumpulkan data sesuai kebijakan privasi mereka sendiri untuk keperluan personalisasi iklan. Kami akan memperbarui bagian ini apabila jaringan iklan pihak ketiga tertentu resmi digunakan secara tetap di Layanan.</p>

            <h2>4. Bagaimana Kami Menggunakan Informasi</h2>
            <p>Informasi yang kami kumpulkan digunakan untuk: mengoperasikan dan meningkatkan Layanan, membalas pertanyaan/pesan Anda, mengirim notifikasi yang Anda pilih untuk terima, memahami pola penggunaan situs secara agregat, mencegah spam/penyalahgunaan, dan menjaga keamanan Layanan.</p>

            <h2>5. Berbagi Informasi dengan Pihak Ketiga</h2>
            <p>Kami tidak menjual data pribadi Anda. Informasi teknis tertentu diproses oleh penyedia layanan pihak ketiga yang mendukung operasional Layanan, yaitu Google (Analytics, Firebase Cloud Messaging), Cloudflare (Turnstile), dan CoinGecko (data harga), serta berpotensi jaringan iklan (lihat bagian Iklan). Kami hanya membagikan data seminimal yang diperlukan agar layanan tersebut berfungsi.</p>

            <h2>6. Keamanan Data</h2>
            <p>Kami menerapkan langkah-langkah teknis yang wajar untuk melindungi data yang tersimpan di server kami. Namun, tidak ada metode transmisi atau penyimpanan data melalui internet yang 100% aman, sehingga kami tidak dapat menjamin keamanan mutlak.</p>

            <h2>7. Hak Anda</h2>
            <p>Anda dapat: menonaktifkan notifikasi push kapan saja lewat pengaturan aplikasi/perangkat; menghapus preferensi lokal (cache, dll.) lewat pengaturan browser; serta meminta kami menghapus data yang Anda kirimkan lewat formulir Kontak dengan menghubungi kami menggunakan detail di bagian bawah halaman ini.</p>

            <h2>8. Privasi Anak</h2>
            <p>Layanan ini ditujukan untuk khalayak umum penggemar crypto dan tidak secara khusus ditujukan untuk anak-anak di bawah 13 tahun. Kami tidak dengan sengaja mengumpulkan data pribadi dari anak-anak di bawah usia tersebut.</p>

            <h2>9. Perubahan Kebijakan</h2>
            <p>Kami dapat memperbarui Kebijakan Privasi ini dari waktu ke waktu mengikuti perubahan praktik pengelolaan data di Layanan. Tanggal "Terakhir diperbarui" di bagian atas halaman ini akan disesuaikan setiap kali ada perubahan.</p>

            <h2>10. Hubungi Kami</h2>
            <p>Jika Anda memiliki pertanyaan mengenai Kebijakan Privasi ini atau ingin mengajukan permintaan terkait data Anda, silakan hubungi kami melalui <a href="index.php#kontak">halaman Kontak</a>.</p>

        </div>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
