<?php

namespace Tests\Feature\Pendaftaran;

use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Services\KtpStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnkripsiDataSensitifTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
    }

    public function test_nik_tersimpan_terenkripsi_di_database(): void
    {
        $pelanggan = Pelanggan::factory()->create(['nik' => '1234567890123456']);

        $rawDb = DB::table('pelanggan')
            ->where('id', $pelanggan->id)
            ->first();

        $this->assertNotSame('1234567890123456', $rawDb->nik);
        $this->assertSame('1234567890123456', Crypt::decryptString($rawDb->nik));
        $this->assertSame(hash('sha256', '1234567890123456'), $rawDb->nik_hash);
    }

    public function test_nik_hash_dipakai_untuk_cek_duplikat(): void
    {
        PaketInternet::factory()->create();
        Pelanggan::factory()->create(['nik' => '1111222233334444']);

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '1111222233334444',
            'nomor_hp' => '081234567890',
            'email' => 'budi@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'reguler',
            'paket_internet_id' => 1,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('nik');
    }

    public function test_foto_ktp_tersimpan_terenkripsi_di_private_disk_dan_bisa_dipreview_admin(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        // Tersimpan di PRIVATE disk, bukan public.
        $this->assertNotNull($pelanggan->foto_ktp);
        $this->assertTrue(str_ends_with($pelanggan->foto_ktp, '.webp'));
        $this->assertTrue(Storage::disk('private')->exists($pelanggan->foto_ktp));
        $this->assertFalse(Storage::disk('public')->exists($pelanggan->foto_ktp));

        // Konten di disk ter-enkripsi, dan bisa didecrypt kembali ke WebP asli.
        $isiDisk = Storage::disk('private')->get($pelanggan->foto_ktp);
        $this->assertStringStartsWith("\x52\x49\x46\x46", Crypt::decryptString($isiDisk));

        // Admin yang berwenang bisa preview lewat endpoint ber-auth.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp");

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringStartsWith("\x52\x49\x46\x46", $response->getContent());
    }
}