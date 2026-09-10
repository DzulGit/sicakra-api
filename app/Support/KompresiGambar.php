<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Kompres foto yang diunggah menjadi WebP secara otomatis (GD native,
 * tanpa package tambahan). Setiap alur upload mewajibkan hasil .webp
 * agar lebih ringan — pola dipakai: foto_ktp, laporan kendala,
 * dokumentasi kerja teknisi, foto profil, dll.
 *
 * Non-circle:
 *  - Format yang tidak dikenal GD (HEIC, WebP lama? tidak — WebP didukung,
 *    HEIC/HEIF dari iPhone TIDAK) di-fallback disimpan mentah supaya upload
 *    tidak pernah gagal. Tingkatkan dengan libheif kalau HEIC jadi kebutuhan.
 *  - GIF != foto → frame pertama WebP (sesuai konteks unggahan foto).
 */
class KompresiGambar
{
    /**
     * @param  int  $kualitas  0-100, 80 = kompromi ringan tapi tetap tajam.
     */
    public static function simpanKeWebp(UploadedFile $file, string $folder, int $kualitas = 80): string
    {
        $gambar = self::decodifikasi($file);

        if ($gambar === null) {
            return Storage::disk('public')->putFile($folder, $file);
        }

        $gambar = self::terapkanOrientasiExif($gambar, $file);

        // Pertahankan alpha PNG transparan (mis. logo/scan dgn latar transparan).
        imagealphablending($gambar, false);
        imagesavealpha($gambar, true);

        $stream = fopen('php://temp', 'r+');
        imagewebp($gambar, $stream, $kualitas);
        rewind($stream);
        $isi = stream_get_contents($stream);
        fclose($stream);
        imagedestroy($gambar);

        $nama = $folder.'/'.now()->format('YmdHis').'_'.bin2hex(random_bytes(4)).'.webp';

        Storage::disk('public')->put($nama, $isi);

        return $nama;
    }

    private static function decodifikasi(UploadedFile $file): ?GdImage
    {
        $gambar = @imagecreatefromstring($file->get());

        return $gambar instanceof GdImage ? $gambar : null;
    }

    /**
     * Kamera HP menyimpan JPEG dengan tag EXIF Orientation; GD yang memahami
     * piksel mentah TIDAK menerapkan tag itu, dan hasil re-encode WebP umumnya
     * tidak lagi membawa tag EXIF — tanpa koreksi manual foto hasil jepretan
     * ponsel jadi miring setelah dikonversi.
     */
    private static function terapkanOrientasiExif(GdImage $gambar, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || $file->getMimeType() !== 'image/jpeg') {
            return $gambar;
        }

        $exif = @exif_read_data((string) $file->getRealPath());
        $orientasi = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $sudut = match ($orientasi) {
            3 => 180,
            6 => 90,
            8 => -90,
            default => 0,
        };

        if ($sudut === 0) {
            return $gambar;
        }

        if (! imageistruecolor($gambar)) {
            imagepalettetotruecolor($gambar);
        }

        $dirotasi = imagerotate($gambar, $sudut, 0);

        return $dirotasi === false ? $gambar : $dirotasi;
    }
}
