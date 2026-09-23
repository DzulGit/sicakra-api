<?php

namespace Tests\Feature\Security;

use App\Models\Pelanggan;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * php artisan ktp:encrypt-existing
 *
 * Migrasi foto KTP lama (plaintext di public) menjadi ter-enkripsi di disk
 * private. Idempotent, mendukung --dry-run, dan TIDAK pernah menghapus file
 * sumber sebelum encrypted copy tersimpan + update DB + verifikasi decrypt.
 */
class EnkripsiKtpCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
    }

    /** Simulasikan file KTP lama: plaintext di public, path tercatat di DB. */
    private function pasangLegacy(string $pathLama, string $isi): Pelanggan
    {
        Storage::disk('public')->put($pathLama, $isi);

        return Pelanggan::factory()->create(['foto_ktp' => $pathLama]);
    }

    public function test_plaintext_public_dimigrasikan_ke_private_terenkripsi_dan_sumber_dihapus(): void
    {
        $isiAsli = 'foto-ktp-plaintext-'.bin2hex(random_bytes(8));
        $pelanggan = $this->pasangLegacy('ktp/lama.webp', $isiAsli);

        $this->artisan('ktp:encrypt-existing')
            ->expectsOutputToContain('Foto KTP dimigrasikan (plaintext → ter-enkripsi): 1')
            ->assertExitCode(0);

        $pathBaru = $pelanggan->fresh()->foto_ktp;

        $this->assertNotSame('ktp/lama.webp', $pathBaru);
        $this->assertStringStartsWith('ktp/', $pathBaru);

        // Sumber public terhapus hanya setelah encrypted copy tersimpan & DB sukses.
        $this->assertFalse(Storage::disk('public')->exists('ktp/lama.webp'));

        // File private ter-enkripsi dan bisa didekripsi kembali ke isi asli.
        $this->assertTrue(Storage::disk('private')->exists($pathBaru));
        $iniTerbaru = Storage::disk('private')->get($pathBaru);
        $this->assertNotSame($isiAsli, $iniTerbaru);
        $this->assertSame($isiAsli, Crypt::decryptString($iniTerbaru));
    }

    public function test_dry_run_tidak_mengubah_file_atau_database(): void
    {
        $isiAsli = 'legacy-dry-run-'.bin2hex(random_bytes(8));
        $pelanggan = $this->pasangLegacy('ktp/dry.webp', $isiAsli);

        $this->artisan('ktp:encrypt-existing', ['--dry-run' => true])
            ->expectsOutputToContain('MODUS DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame('ktp/dry.webp', $pelanggan->fresh()->foto_ktp);
        $this->assertTrue(Storage::disk('public')->exists('ktp/dry.webp'));
        $this->assertSame([], Storage::disk('private')->allFiles('ktp'));
    }

    public function test_gagal_enkripsi_tidak_menghapus_sumber_dan_tidak_mengubah_db(): void
    {
        $isiAsli = 'foto-ktp-akan-gagal-'.bin2hex(random_bytes(8));
        $pelanggan = $this->pasangLegacy('ktp/gagal.webp', $isiAsli);

        // Simulasi kegagalan enkripsi; method lain (mis. decryptString untuk
        // verifikasi & deteksi "sudah ciphertext") tetap berjalan nyata.
        $encrypter = Crypt::getFacadeRoot();
        $mock = Mockery::mock($encrypter)->makePartial();
        $mock->shouldReceive('encryptString')
            ->andThrow(new EncryptException('Simulasi kegagalan enkripsi'));
        Crypt::swap($mock);

        $this->artisan('ktp:encrypt-existing')->assertExitCode(1);

        $this->assertSame('ktp/gagal.webp', $pelanggan->fresh()->foto_ktp);
        $this->assertTrue(Storage::disk('public')->exists('ktp/gagal.webp'));
        $this->assertSame([], Storage::disk('private')->allFiles('ktp'));
    }

    public function test_idempotent_dijalankan_dua_kali_tidak_membuat_duplikat(): void
    {
        $isiAsli = 'foto-ktp-idempoten-'.bin2hex(random_bytes(8));
        $pelanggan = $this->pasangLegacy('ktp/idem.webp', $isiAsli);

        $this->artisan('ktp:encrypt-existing')->assertExitCode(0);
        $pathSekali = $pelanggan->fresh()->foto_ktp;

        $this->artisan('ktp:encrypt-existing')
            ->expectsOutputToContain('Dilewati (sudah ter-enkripsi di private): 1')
            ->assertExitCode(0);

        $this->assertSame($pathSekali, $pelanggan->fresh()->foto_ktp);
        $this->assertCount(1, Storage::disk('private')->files('ktp'));
    }

    public function test_file_sudah_terenkripsi_di_public_dipindah_tanpa_double_encrypt(): void
    {
        // Skenario sisa: hasil enkripsi lama masih tertinggal di public.
        $isiCipher = Crypt::encryptString('sudah-ciphertext');
        $pelanggan = $this->pasangLegacy('ktp/cipher.webp', $isiCipher);

        $this->artisan('ktp:encrypt-existing')->assertExitCode(0);

        $pathBaru = $pelanggan->fresh()->foto_ktp;

        $this->assertNotSame('ktp/cipher.webp', $pathBaru);
        $this->assertTrue(Storage::disk('private')->exists($pathBaru));
        $this->assertFalse(Storage::disk('public')->exists('ktp/cipher.webp'));

        // Tidak di-double-encrypt: decrypt sekali saja sudah kembali ke string asli.
        $this->assertSame('sudah-ciphertext', Crypt::decryptString(Storage::disk('private')->get($pathBaru)));
    }
}