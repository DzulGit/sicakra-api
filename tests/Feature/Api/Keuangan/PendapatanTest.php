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

class PendapatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function buatPembayaranBerhasil(): void
    {
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
    }

    public function test_keuangan_bisa_melihat_pendapatan_per_bulan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $bulan = now()->month;
        $qs = http_build_query(['tahun' => now()->year])."&bulan%5B%5D={$bulan}";
        $response = $this->getJson('/api/admin/keuangan/pendapatan?'.$qs);

        $response->assertOk()
            ->assertJsonPath('data.stats.total_pendapatan', 'Rp 150.000')
            ->assertJsonPath('data.stats.jumlah_pembayaran', 1)
            ->assertJsonCount(now()->daysInMonth, 'data.tren')
            ->assertJsonCount(1, 'data.pembayaran_terbaru');
    }

    public function test_pendapatan_tanpa_bulan_mengembalikan_tren_tahunan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/keuangan/pendapatan?tahun='.now()->year);

        $response->assertOk()
            ->assertJsonPath('data.filter.bulan', null)
            ->assertJsonCount(12, 'data.tren');
    }

    public function test_filter_pelanggan_ids(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $pelanggan = Pelanggan::first();
        $qs = http_build_query(['tahun' => now()->year])."&pelanggan_ids%5B%5D={$pelanggan->id}";
        $response = $this->getJson('/api/admin/keuangan/pendapatan?'.$qs);

        $response->assertOk()
            ->assertJsonPath('data.stats.jumlah_pembayaran', 1);
    }

    public function test_filter_multi_bulan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $bulan = now()->month;
        $qs = http_build_query(['tahun' => now()->year])."&bulan%5B%5D={$bulan}&bulan%5B%5D=1";
        $response = $this->getJson('/api/admin/keuangan/pendapatan?'.$qs);

        $response->assertOk()
            ->assertJsonCount(2, 'data.tren');
    }

    public function test_report_pdf_bulanan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/keuangan/pendapatan/report', [
            'tahun' => now()->year,
            'bulan' => [now()->month],
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_report_excel_bulanan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatPembayaranBerhasil();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/keuangan/pendapatan/report/excel', [
            'tahun' => now()->year,
            'bulan' => [now()->month],
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_operasional_tidak_bisa_akses_pendapatan(): void
    {
        $admin = Admin::factory()->operasional()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/keuangan/pendapatan')->assertForbidden();
    }
}
