<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardKeuanganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_keuangan_bisa_melihat_ringkasan_dashboard(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 150000,
        ]);
        Pembayaran::factory()->create([
            'tagihan_id' => $tagihan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 150000,
            'dibayar_pada' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/keuangan/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.pembayaran_hari_ini', 1)
            ->assertJsonPath('data.stats.pendapatan_bulan_ini', 'Rp 150.000')
            ->assertJsonCount(12, 'data.tren_pendapatan')
            ->assertJsonCount(1, 'data.pembayaran_terbaru');
    }

    public function test_dashboard_mengelompokkan_tagihan_tertunggak(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'tanggal_jatuh_tempo' => now()->subDay()->toDateString(),
            'total_tagihan' => 150000,
        ]);
        $layananLain = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        Tagihan::factory()->create([
            'layanan_internet_id' => $layananLain->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'tanggal_jatuh_tempo' => now()->addDays(3)->toDateString(),
            'total_tagihan' => 200000,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/keuangan/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.tagihan_tertunggak', 1)
            ->assertJsonPath('data.stats.total_tertunggak', 'Rp 150.000')
            ->assertJsonPath('data.stats.jatuh_tempo_minggu_ini', 1)
            ->assertJsonCount(1, 'data.tagihan_akan_jatuh_tempo');
    }

    public function test_operasional_tidak_bisa_melihat_dashboard_keuangan(): void
    {
        $admin = Admin::factory()->operasional()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/keuangan/dashboard')->assertForbidden();
    }
}
