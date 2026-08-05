<?php

namespace Tests\Feature\Api\Teknisi;

use App\Enums\HasilKerjaEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTeknisiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teknisi_bisa_melihat_ringkasan_dashboard(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $permohonan = PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DIJADWALKAN,
        ]);
        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->toDateString(),
        ]);
        $jadwal->teknisi()->attach($teknisi->id);

        Sanctum::actingAs($teknisi);

        $response = $this->getJson('/api/admin/teknisi/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.pekerjaan_hari_ini', 1)
            ->assertJsonPath('data.stats.sedang_dikerjakan', 1)
            ->assertJsonCount(1, 'data.jadwal_hari_ini')
            ->assertJsonPath('data.jadwal_hari_ini.0.pelanggan', $permohonan->pelanggan->nama_lengkap);
    }

    public function test_dashboard_hanya_menampilkan_pekerjaan_milik_teknisi_sendiri(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $teknisiLain = Admin::factory()->teknisi()->create();
        $permohonan = PermohonanLayanan::factory()->create();
        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->toDateString(),
        ]);
        $jadwal->teknisi()->attach($teknisiLain->id);

        Sanctum::actingAs($teknisi);

        $response = $this->getJson('/api/admin/teknisi/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.pekerjaan_hari_ini', 0)
            ->assertJsonCount(0, 'data.jadwal_hari_ini');
    }

    public function test_dashboard_menampilkan_tiket_kendala_ditugaskan(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        LaporanKendala::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status' => StatusLaporanEnum::DITUGASKAN,
            'ditugaskan_ke' => $teknisi->id,
        ]);

        Sanctum::actingAs($teknisi);

        $response = $this->getJson('/api/admin/teknisi/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.tiket_kendala_aktif', 1)
            ->assertJsonCount(1, 'data.tiket_kendala_aktif');
    }

    public function test_operasional_tidak_bisa_melihat_dashboard_teknisi(): void
    {
        $admin = Admin::factory()->operasional()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/teknisi/dashboard')->assertForbidden();
    }

    public function test_riwayat_pekerjaan_menampilkan_hasil_yang_sudah_diisi(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $permohonan = PermohonanLayanan::factory()->create();
        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->toDateString(),
            'hasil' => HasilKerjaEnum::SELESAI,
            'diisi_oleh' => $teknisi->id,
        ]);
        $jadwal->teknisi()->attach($teknisi->id);

        Sanctum::actingAs($teknisi);

        $response = $this->getJson('/api/admin/teknisi/dashboard');

        $response->assertOk()
            ->assertJsonCount(1, 'data.riwayat_pekerjaan')
            ->assertJsonPath('data.riwayat_pekerjaan.0.status', 'selesai');
    }
}
