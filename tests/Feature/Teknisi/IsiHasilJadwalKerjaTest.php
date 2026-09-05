<?php

namespace Tests\Feature\Teknisi;

use App\Enums\StatusPermohonanEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\PaketInternet;
use App\Models\PermohonanLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IsiHasilJadwalKerjaTest extends TestCase
{
    use RefreshDatabase;

    private function buatJadwalUntukTeknisi(Admin $teknisi): JadwalKerja
    {
        $paket = PaketInternet::factory()->create();
        $permohonan = PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'paket_internet_id' => $paket->id,
        ]);
        $permohonan->pelanggan()->update(['nomor_pelanggan' => null]);

        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->toDateString(),
        ]);
        $jadwal->teknisi()->attach($teknisi->id);

        return $jadwal;
    }

    public function test_hasil_selesai_membutuhkan_foto_dan_koordinat(): void
    {
        Storage::fake('public');
        $teknisi = Admin::factory()->teknisi()->create();
        $jadwal = $this->buatJadwalUntukTeknisi($teknisi);

        Sanctum::actingAs($teknisi);

        $this->patchJson("/api/admin/teknisi/jadwal-kerja/{$jadwal->id}/hasil", [
            'hasil' => 'selesai',
        ])->assertUnprocessable();
    }

    public function test_hasil_selesai_dengan_foto_dan_koordinat_berhasil(): void
    {
        Storage::fake('public');
        $teknisi = Admin::factory()->teknisi()->create();
        $jadwal = $this->buatJadwalUntukTeknisi($teknisi);

        Sanctum::actingAs($teknisi);

        $response = $this->patch("/api/admin/teknisi/jadwal-kerja/{$jadwal->id}/hasil", [
            'hasil' => 'selesai',
            'foto_dokumentasi' => [
                UploadedFile::fake()->image('dokumen.jpg'),
            ],
            'latitude_hasil' => -6.2500000,
            'longitude_hasil' => 106.8100000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.ringkasan_aktivasi.nomor_pelanggan', fn ($v) => $v !== null);

        $jadwal->refresh();
        $this->assertCount(1, $jadwal->foto_dokumentasi);
        $this->assertEquals('-6.2500000', (string) $jadwal->latitude_hasil);
        $this->assertEquals('106.8100000', (string) $jadwal->longitude_hasil);

        $permohonan = $jadwal->permohonanLayanan;
        $this->assertTrue($permohonan->status === StatusPermohonanEnum::DIKONVERSI);
        $this->assertEquals('-6.2500000', $permohonan->latitude);
        $this->assertEquals('106.8100000', $permohonan->longitude);
    }

    public function test_hasil_kendala_tetap_berlaku_tanpa_foto(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $jadwal = $this->buatJadwalUntukTeknisi($teknisi);

        Sanctum::actingAs($teknisi);

        $this->patchJson("/api/admin/teknisi/jadwal-kerja/{$jadwal->id}/hasil", [
            'hasil' => 'kendala',
            'catatan_kendala' => 'Jaringan ODP belum tersedia.',
        ])->assertOk();

        $this->assertDatabaseHas('permohonan_layanan', [
            'id' => $jadwal->permohonan_layanan_id,
            'status' => StatusPermohonanEnum::DITUNDA->value,
        ]);
    }
}