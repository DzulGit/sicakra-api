<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_operasional_bisa_melihat_statistik_semua_reseller(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => PaketInternet::factory()->create(['reseller_id' => $reseller->id])->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 100000,
        ]);
        Pembayaran::factory()->create([
            'tagihan_id' => $tagihan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 100000,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/statistik')
            ->assertOk()
            ->assertJsonPath('data.stats.total_reseller', 1)
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.total_pendapatan', 100000)
            ->assertJsonPath('data.distribusi_pelanggan.0.label', $reseller->nama_lengkap)
            ->assertJsonCount(1, 'data.transaksi_terbaru');
    }

    public function test_statistik_per_reseller_mengembalikan_trend_status_dan_distribusi_paket(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id, 'nama_paket' => 'Wifi 20 Mbps']);
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/reseller/{$reseller->id}/statistik")
            ->assertOk()
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.pelanggan_aktif', 1)
            ->assertJsonPath('data.stats.total_paket', 1)
            ->assertJsonPath('data.distribusi_paket.0.label', 'Wifi 20 Mbps')
            ->assertJsonCount(12, 'data.trend_pendapatan');
    }

    public function test_reseller_tidak_bisa_mengakses_data_reseller_lain(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/statistik')
            ->assertForbidden();
    }

    public function test_laporan_pdf_dan_excel_dapat_diunduh(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/reseller/laporan', [
                'reseller_id' => null,
                'tahun' => 2026,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/reseller/laporan/excel', [
                'reseller_id' => null,
                'tahun' => 2026,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}