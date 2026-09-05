<?php

namespace Tests\Feature\Pendaftaran;

use App\Models\PaketInternet;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PendaftaranTest extends TestCase
{
    use RefreshDatabase;

    public function test_pelanggan_baru_bisa_mendaftar(): void
    {
        Storage::fake('public');

        $paket = PaketInternet::factory()->create();

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '1234567890123456',
            'nomor_hp' => '081234567890',
            'email' => 'budi@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'reguler',
            'paket_internet_id' => $paket->id,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('pelanggan', ['nik' => '1234567890123456']);
        $this->assertDatabaseHas('permohonan_layanan', ['status' => 'MENUNGGU_PENGECEKAN_TEKNIS']);
    }

    public function test_pendaftaran_dengan_paket_custom_berhasil_tanpa_paket_internet_id(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Siti Aminah',
            'nik' => '9999888877776666',
            'nomor_hp' => '087777777777',
            'email' => 'siti@example.com',
            'alamat_pemasangan' => 'Jl. Sudirman No. 5',
            'rt' => '003',
            'rw' => '004',
            'kode_pos' => '50001',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom 100 Mbps',
            'kecepatan_custom_mbps' => 100,
            'catatan_custom' => 'Untuk kebutuhan kantor kecil',
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('permohonan_layanan', [
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom 100 Mbps',
        ]);
    }

    public function test_pendaftaran_gagal_kalau_nik_sudah_terdaftar(): void
    {
        Storage::fake('public');

        Pelanggan::factory()->create(['nik' => '1234567890123456']);

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '1234567890123456',
            'nomor_hp' => '089999999999',
            'email' => 'budi@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom 100mbps',
            'kecepatan_custom_mbps' => 100,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('nik');
    }

    public function test_pendaftaran_berhasil_tanpa_foto_selfie(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '1111222233334444',
            'nomor_hp' => '081111222233',
            'email' => 'budi2@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom',
            'kecepatan_custom_mbps' => 50,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pelanggan', ['nik' => '1111222233334444', 'foto_selfie_ktp' => null]);
    }

    public function test_pendaftaran_gagal_tanpa_foto_ktp(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '9999888877776666',
            'nomor_hp' => '089999999999',
            'email' => 'budi@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom',
            'kecepatan_custom_mbps' => 50,
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('foto_ktp');
    }

    public function test_pendaftaran_gagal_tanpa_email(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '9999888877776666',
            'nomor_hp' => '089999999999',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'custom',
            'nama_paket_custom' => 'Custom',
            'kecepatan_custom_mbps' => 50,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
