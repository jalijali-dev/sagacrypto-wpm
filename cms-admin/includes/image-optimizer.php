<?php
declare(strict_types=1);

/**
 * Probe kemampuan resize/convert gambar di server ini. GD diprioritaskan
 * (lebih umum tersedia di shared hosting); Imagick belum diimplementasikan
 * sebagai jalur alternatif — lihat cms_image_optimize() bila suatu saat
 * dibutuhkan untuk server tanpa GD. Lihat docs/IMAGE_OPTIMIZATION_PLAN.md.
 */
function cms_image_optimizer_capabilities(): array
{
    $gd   = extension_loaded('gd') && function_exists('imagecreatetruecolor');
    $webp = false;
    if ($gd && function_exists('gd_info')) {
        $info = gd_info();
        $webp = !empty($info['WebP Support']) && function_exists('imagewebp');
    }

    return [
        'gd'      => $gd,
        'imagick' => extension_loaded('imagick') && class_exists('Imagick'),
        'webp'    => $webp,
    ];
}

/**
 * Optimasi satu file gambar: resize proporsional (max width, tidak pernah
 * upscale) + pilih format output + kompres. Hanya dipanggil untuk MIME
 * image/jpeg dan image/png (GIF/WebP/PDF di-skip oleh caller sebelum
 * fungsi ini dipanggil sama sekali — lihat media-library.php).
 *
 * TIDAK PERNAH throw — kegagalan apa pun (library tidak ada, file korup,
 * encode gagal, dst) menghasilkan ok=false, caller wajib fallback ke file
 * sumber apa adanya.
 *
 * PNG dengan transparansi NYATA (bukan cuma channel alpha kosong) tetap
 * disimpan sebagai PNG, cuma di-resize + di-recompress lossless — dianggap
 * proxy untuk logo/ikon/screenshot yang butuh transparansi. PNG tanpa
 * transparansi nyata dan semua JPEG dikonversi ke WebP (kalau server
 * mendukung) atau JPEG (fallback), quality 80.
 *
 * @return array{ok: bool, output_path: string, output_filename: string, output_mime: string, skipped_reason: string}
 */
function cms_image_optimize(string $sourcePath, string $targetDir, string $baseFilename, int $maxWidth = 1200, int $quality = 80): array
{
    $fail = static fn (string $reason): array => [
        'ok' => false, 'output_path' => '', 'output_filename' => '', 'output_mime' => '', 'skipped_reason' => $reason,
    ];

    if (!is_file($sourcePath)) {
        return $fail('source_missing');
    }

    $caps = cms_image_optimizer_capabilities();
    if (!$caps['gd']) {
        // Imagick-only servers are not handled yet — production has GD, so
        // this path is a safety net, not the expected case.
        return $fail('no_gd');
    }

    $info = @getimagesize($sourcePath);
    if ($info === false) {
        return $fail('invalid_image');
    }
    $srcWidth  = (int) $info[0];
    $srcHeight = (int) $info[1];
    $mime      = (string) ($info['mime'] ?? '');

    if (!in_array($mime, ['image/jpeg', 'image/png'], true) || $srcWidth <= 0 || $srcHeight <= 0) {
        return $fail('unsupported_mime');
    }

    $srcImage = $mime === 'image/jpeg' ? @imagecreatefromjpeg($sourcePath) : @imagecreatefrompng($sourcePath);
    if ($srcImage === false) {
        return $fail('decode_failed');
    }

    $hasTransparency = false;
    if ($mime === 'image/png') {
        imagepalettetotruecolor($srcImage);
        $hasTransparency = cms_image_has_real_transparency($srcImage, $srcWidth, $srcHeight);
    }

    // --- Resize: proportional, never upscale ---------------------------------
    $targetWidth  = $srcWidth;
    $targetHeight = $srcHeight;
    if ($srcWidth > $maxWidth) {
        $targetWidth  = $maxWidth;
        $targetHeight = max(1, (int) round($srcHeight * ($maxWidth / $srcWidth)));
    }

    if ($targetWidth !== $srcWidth) {
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            imagedestroy($srcImage);
            return $fail('resize_failed');
        }
        if ($hasTransparency) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        $resampleOk = imagecopyresampled(
            $resized, $srcImage, 0, 0, 0, 0,
            $targetWidth, $targetHeight, $srcWidth, $srcHeight
        );
        imagedestroy($srcImage);
        if (!$resampleOk) {
            imagedestroy($resized);
            return $fail('resample_failed');
        }
        $workImage = $resized;
    } else {
        $workImage = $srcImage;
    }

    // --- Pick output format ----------------------------------------------------
    if ($hasTransparency) {
        $outExt  = 'png';
        $outMime = 'image/png';
    } elseif ($caps['webp']) {
        $outExt  = 'webp';
        $outMime = 'image/webp';
    } else {
        $outExt  = 'jpg';
        $outMime = 'image/jpeg';
    }

    // Fresh random suffix — guarantees the output filename never collides
    // with the source file, even when the extension stays the same
    // (e.g. jpeg source re-encoded back to jpg).
    $outputFilename = $baseFilename . '-opt' . bin2hex(random_bytes(4)) . '.' . $outExt;
    $outputPath     = rtrim($targetDir, '/') . '/' . $outputFilename;

    if ($outExt === 'png') {
        imagesavealpha($workImage, true);
    }

    $encodeOk = match ($outExt) {
        'webp'  => imagewebp($workImage, $outputPath, $quality),
        'jpg'   => imagejpeg($workImage, $outputPath, $quality),
        'png'   => imagepng($workImage, $outputPath, 6), // lossless; level 6 = balanced speed/size
        default => false,
    };

    imagedestroy($workImage);

    if (!$encodeOk || !is_file($outputPath)) {
        return $fail('encode_failed');
    }
    @chmod($outputPath, 0644);

    return [
        'ok'              => true,
        'output_path'     => $outputPath,
        'output_filename' => $outputFilename,
        'output_mime'     => $outMime,
        'skipped_reason'  => '',
    ];
}

/**
 * Scan alpha channel (sampling grid, bukan tiap piksel — cukup untuk
 * gambar berukuran wajar setelah lolos limit upload) untuk cari
 * transparansi yang BENAR-BENAR dipakai, bukan cuma channel yang ada di
 * format tapi semua piksel opaque. Proxy untuk membedakan logo/ikon/
 * screenshot (butuh transparansi) dari foto/ilustrasi PNG biasa — bukan
 * klasifikasi semantik, lihat docs/IMAGE_OPTIMIZATION_PLAN.md §3a untuk
 * kasus tepi yang diterima.
 */
function cms_image_has_real_transparency($image, int $width, int $height): bool
{
    $stepX = max(1, (int) floor($width / 100));
    $stepY = max(1, (int) floor($height / 100));

    for ($y = 0; $y < $height; $y += $stepY) {
        for ($x = 0; $x < $width; $x += $stepX) {
            $rgba  = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F; // GD: 0 = opaque, 127 = fully transparent
            if ($alpha > 0) {
                return true;
            }
        }
    }

    return false;
}
