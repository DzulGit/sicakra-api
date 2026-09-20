<?php

namespace Tests\Feature\Pendaftaran;

use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Support\KompresiGambar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnkripsiDataSensitifTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_foto_ktp_tersimpan_terenkripsi_dan_bisa_dimuat_lewat_url_bertanda_tangan(): void
    {
        Storage::fake('public');

        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KompresiGambar::simpanKeWebp(
                UploadedFile::fake()->image('ktp.jpg'),
                'ktp',
                enkripsi: true,
            ),
        ]);

        $isiDisk = Storage::disk('public')->get($pelanggan->foto_ktp);
        $this->assertStringStartsWith("\x52\x49\x46\x46", Crypt::decryptString($isiDisk));

        $response = $this->get($pelanggan->foto_ktp_url);
        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringStartsWith("\x52\x49\x46\x46", $response->getContent());
    }
}
