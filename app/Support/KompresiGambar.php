<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
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
     * @param  bool  $enkripsi  true = isi file langsung ditulis sebagai ciphertext
     *                          AES-256 (Crypt + APP_KEY) — tanpa copy plaintext
     *                          perantara di disk. Dipakai dokumen sensitif (foto KTP),
     *                          konten dikembalikan mentah lewat endpoint ber-authorize.
     * @param  string  $disk  disk tujuan. Harus bernilai 'private' untuk dokumen sensitif.
     */
    public static function simpanKeWebp(
        UploadedFile $file,
        string $folder,
        int $kualitas = 80,
        bool $enkripsi = false,
        string $disk = 'public',
    ): string {
        $gambar = self::decodifikasi($file);

        $nama = $folder.'/'.now()->format('YmdHis').'_'.bin2hex(random_bytes(4));

        if ($gambar === null) {
            // Format yang tidak dikenal GD (mis. HEIC) → simpan mentah. Ekstensi
            // asli dipertahankan agar MIME tetap dikenali saat disajikan nanti.
            $nama .= '.'.self::ekstensiAman($file);
            $isi = $file->get();
        } else {
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

            $nama .= '.webp';
        }

        // Dokumen sensitif langsung ditulis dalam bentuk ter-enkripsi.
        Storage::disk($disk)->put($nama, $enkripsi ? Crypt::encryptString($isi) : $isi);

        return $nama;
    }

    private static function ekstensiAman(UploadedFile $file): string
    {
        $ekstensi = strtolower((string) $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $ekstensi) ? $ekstensi : 'bin';
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
