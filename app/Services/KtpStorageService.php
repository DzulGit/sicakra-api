<?php

namespace App\Services;

use App\Models\Pelanggan;
use App\Support\KompresiGambar;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penyimpanan & penyajian foto KTP yang aman.
 *
 * Aturan keamanan:
 *  - Selalu disimpan di disk PRIVATE (storage/app/private), TIDAK pernah di public.
 *  - Isi file ter-enkripsi AES-256-CBC pakai Crypt + APP_KEY Laravel (bukan key baru).
 *  - Database hanya menyimpan path relatif; path SELALU berasal dari record
 *    Pelanggan yang sudah di-authorize — tidak pernah dari input request
 *    (aman dari path traversal / arbitrary file read).
 *  - Tidak ada URL public / Storage::url() untuk dokumen ini.
 */
class KtpStorageService
{
    public const DISK_PRIVATE = 'private';

    public const FOLDER_KTP = 'ktp';

    /** Simpan foto KTP baru. MIME/ukuran sudah divalidasi FormRequest pemanggil. */
    public static function simpan(UploadedFile $file): string
    {
        return KompresiGambar::simpanKeWebp(
            $file,
            self::FOLDER_KTP,
            enkripsi: true,
            disk: self::DISK_PRIVATE,
        );
    }

    /**
     * Ganti foto KTP pelanggan:
     *   1. upload baru + enkripsi + simpan ke private disk,
     *   2. update DB,
     *   3. barulah hapus file lama (kalau update DB gagal, file baru dibersihkan
     *      dan file lama tetap utuh).
     */
    public static function ganti(Pelanggan $pelanggan, UploadedFile $file): string
    {
        $pathLama = $pelanggan->foto_ktp;
        $pathBaru = self::simpan($file);

        try {
            $pelanggan->update(['foto_ktp' => $pathBaru]);
        } catch (\Throwable $e) {
            // Update DB gagal → jangan biarkan file baru jadi orphan.
            self::hapusTerkait($pathBaru);
            throw $e;
        }

        if ($pathLama && $pathLama !== $pathBaru) {
            self::hapusTerkait($pathLama);
        }

        return $pathBaru;
    }

    /** Hapus dari disk private (primary) dan disk public (untuk file legacy). */
    public static function hapusTerkait(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk(self::DISK_PRIVATE)->delete($path);
        Storage::disk('public')->delete($path);
    }

    /**
     * Baca & dekripsi foto KTP milik pelanggan.
     *
     * @return array{0:string,1:string,2:string}|null  [isi, mime, namaFile]
     */
    public static function bacaKontenPelanggan(Pelanggan $pelanggan): ?array
    {
        $path = $pelanggan->foto_ktp;

        // Path tidak pernah berasal dari input request, tapi tetap disanitasi:
        // menolak absolute path, '..', '.', backslash, dan segmen kosong.
        if (! $path || ! self::isPathAman($path)) {
            return null;
        }

        $isi = null;

        if (Storage::disk(self::DISK_PRIVATE)->exists($path)) {
            $isi = self::dekripsiAtauKembalikanMentah(
                Storage::disk(self::DISK_PRIVATE)->get($path),
            );
        } elseif (Storage::disk('public')->exists($path)) {
            // Fallback legacy: file lama yang belum sempat dimigrasikan.
            $isi = self::dekripsiAtauKembalikanMentah(
                Storage::disk('public')->get($path),
            );
        }

        if ($isi === null) {
            return null;
        }

        return [$isi, self::mimeUntukNama($path), basename($path)];
    }

    /** Respons HTTP gambar biner — dipakai endpoint yang sudah ber-authorize. */
    public static function responGambar(Pelanggan $pelanggan): Response
    {
        $konten = self::bacaKontenPelanggan($pelanggan);

        if ($konten === null) {
            abort(404, 'Foto KTP tidak ditemukan.');
        }

        [$isi, $mime, $nama] = $konten;

        return response($isi, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$nama.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Coba dekripsi; bila bukan ciphertext (file legacy) kembalikan mentah. */
    public static function dekripsiAtauKembalikanMentah(string $isi): string
    {
        try {
            return Crypt::decryptString($isi);
        } catch (DecryptException) {
            return $isi;
        }
    }

    /** true bila `$isi` adalah ciphertext yang valid (bukan plaintext legacy). */
    public static function dapatDidekripsi(string $isi): bool
    {
        try {
            Crypt::decryptString($isi);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    public static function mimeUntukNama(string $nama): string
    {
        return match (strtolower(pathinfo($nama, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /**
     * true bila path relatif aman untuk dibaca dari storage:
     *  - bukan null/empty,
     *  - bukan absolute path maupun gaya Windows (backslash / drive letter),
     *  - tidak mengandung segmen '..', '.' atau kosong (mis. `a//b`).
     */
    public static function isPathAman(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }

        // Tolak drive letter ala Windows (`C:...`) yang bisa nyelonong dari root.
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segmen) {
            if ($segmen === '' || $segmen === '.' || $segmen === '..') {
                return false;
            }
        }

        return true;
    }
}