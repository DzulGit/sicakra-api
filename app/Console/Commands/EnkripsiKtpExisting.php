<?php

namespace App\Console\Commands;

use App\Models\Pelanggan;
use App\Services\KtpStorageService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Migrasi foto KTP lama (plaintext di public storage) menjadi ter-enkripsi di
 * disk PRIVATE.
 *
 * Properti keamanan command:
 *  - Idempotent: baris yang sudah ter-enkripsi di private disk dilewati, tidak
 *    pernah membuat duplikat.
 *  - Sumber (plaintext) TIDAK dihapus sebelum encrypted copy berhasil ditulis
 *    DAN terverifikasi bisa didekripsi serta update DB berhasil.
 *  - Update DB gagal → file baru dibersihkan, sumber tetap utuh.
 *  - Enkripsi gagal → tidak ada yang dihapus.
 *  - Mendukung --dry-run untuk simulasi tanpa menyentuh file/DB.
 */
class EnkripsiKtpExisting extends Command
{
    protected $signature = 'ktp:encrypt-existing
                            {--dry-run : Simulasi — hanya menampilkan apa yang akan dilakukan, tanpa menulis file atau mengubah database}';

    protected $description = 'Enkripsi AES-256 (APP_KEY) & pindahkan foto KTP lama yang masih plaintext/public ke disk private';

    public function handle(): int
    {
        $hitung = [
            'dimigrasikan' => 0,              // plaintext → ter-enkripsi di private
            'dipindah' => 0,                  // sudah ter-enkripsi tapi masih di public
            'dilewati' => 0,                  // sudah ter-enkripsi di private
            'sumber_tidak_ditemukan' => 0,
            'gagal' => 0,
        ];

        Pelanggan::query()
            ->whereNotNull('foto_ktp')
            ->orderBy('id')
            ->chunkById(200, function ($pelangganList) use (&$hitung) {
                foreach ($pelangganList as $pelanggan) {
                    $hitung[$this->prosesSatu($pelanggan)]++;
                }
            });

        if ($this->option('dry-run')) {
            $this->info('MODUS DRY-RUN — tidak ada file/DB yang diubah.');
        }

        $this->info(implode(PHP_EOL, [
            'Foto KTP dimigrasikan (plaintext → ter-enkripsi): '.$hitung['dimigrasikan'],
            'Dipindah public → private (sudah ter-enkripsi): '.$hitung['dipindah'],
            'Dilewati (sudah ter-enkripsi di private): '.$hitung['dilewati'],
            'Sumber tidak ditemukan: '.$hitung['sumber_tidak_ditemukan'],
            'Gagal: '.$hitung['gagal'],
        ]));

        return $hitung['gagal'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function prosesSatu(Pelanggan $pelanggan): string
    {
        $pathLama = $pelanggan->foto_ktp;
        $dry = (bool) $this->option('dry-run');

        // Sudah ter-migrasi: file ada di private disk dan bisa didekripsi.
        if (Storage::disk(KtpStorageService::DISK_PRIVATE)->exists($pathLama)) {
            if (KtpStorageService::dapatDidekripsi(
                Storage::disk(KtpStorageService::DISK_PRIVATE)->get($pathLama),
            )) {
                return 'dilewati';
            }
        }

        // Tentukan lokasi sumber (public = legacy; private = plaintext tak lazim).
        $sumberDisk = null;
        $isi = null;
        $sudahTerenkripsi = false;

        if (Storage::disk('public')->exists($pathLama)) {
            $sumberDisk = 'public';
            $isi = Storage::disk('public')->get($pathLama);
            $sudahTerenkripsi = KtpStorageService::dapatDidekripsi($isi);
        } elseif (Storage::disk(KtpStorageService::DISK_PRIVATE)->exists($pathLama)) {
            $sumberDisk = KtpStorageService::DISK_PRIVATE;
            $isi = Storage::disk(KtpStorageService::DISK_PRIVATE)->get($pathLama);
        }

        if ($sumberDisk === null) {
            $this->warn("Pelanggan #{$pelanggan->id}: '{$pathLama}' tidak ditemukan di disk mana pun — dilewati.");

            return 'sumber_tidak_ditemukan';
        }

        // Path tujuan tetap di folder ktp/ dengan ekstensi dipertahankan agar
        // MIME tetap bisa dikenali saat disajikan preview.
        $ekstensi = strtolower(pathinfo($pathLama, PATHINFO_EXTENSION));
        if ($ekstensi === '' || preg_match('/^[a-z0-9]{1,10}$/', $ekstensi) !== 1) {
            $ekstensi = 'bin';
        }
        $pathBaru = KtpStorageService::FOLDER_KTP.'/'
            .now()->format('YmdHis').'_'.bin2hex(random_bytes(6)).'.'.$ekstensi;

        try {
            // Sudah ciphertext → pindahkan apa adanya (hindari double-encrypt).
            $isiAkhir = $sudahTerenkripsi
                ? $isi
                : Crypt::encryptString($isi);

            // Verifikasi selalu bisa didecrypt SEBELUM menyentuh DB / hapus sumber.
            if (! $sudahTerenkripsi) {
                Crypt::decryptString($isiAkhir);
            }

            if ($dry) {
                return $sudahTerenkripsi ? 'dipindah' : 'dimigrasikan';
            }

            Storage::disk(KtpStorageService::DISK_PRIVATE)->put($pathBaru, $isiAkhir);

            try {
                $pelanggan->update(['foto_ktp' => $pathBaru]);
            } catch (\Throwable $e) {
                Storage::disk(KtpStorageService::DISK_PRIVATE)->delete($pathBaru);
                $this->error("Pelanggan #{$pelanggan->id}: update DB gagal, file baru dibersihkan, sumber tidak dihapus. {$e->getMessage()}");

                return 'gagal';
            }

            // Sumber lama dihapus HANYA setelah encrypted copy tersimpan & DB sukses.
            Storage::disk($sumberDisk)->delete($pathLama);
        } catch (EncryptException|DecryptException $e) {
            $this->error("Pelanggan #{$pelanggan->id}: enkripsi/verifikasi gagal, sumber tidak dihapus. {$e->getMessage()}");

            return 'gagal';
        }

        return $sudahTerenkripsi ? 'dipindah' : 'dimigrasikan';
    }
}