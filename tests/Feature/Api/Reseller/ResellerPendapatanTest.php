<?php

namespace Tests\Feature\Api\Reseller;

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

class ResellerPendapatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function buatPelangganBerbayar(Admin $reseller, int $nominal): void
    {
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => $nominal,
        ]);
        Pembayaran::factory()->create([
            'tagihan_id' => $tagihan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => $nominal,
            'dibayar_pada' => now(),
        ]);
    }

    public function test_reseller_hanya_melihat_pendapatan_miliknya_sendiri(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $resellerB = Admin::factory()->reseller()->create();
        $this->buatPelangganBerbayar($resellerA, 150000);
        $this->buatPelangganBerbayar($resellerB, 999000);

        Sanctum::actingAs($resellerA);

        $response = $this->getJson('/api/reseller/pendapatan?tahun='.now()->year);

        $response->assertOk()
            ->assertJsonPath('data.stats.total_pendapatan', 'Rp 150.000')
            ->assertJsonPath('data.stats.jumlah_pembayaran', 1)
            ->assertJsonCount(1, 'data.pembayaran_terbaru');
    }

    public function test_reseller_pelanggan_list_hanya_miliknya(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $resellerB = Admin::factory()->reseller()->create();
        $milikA = Pelanggan::factory()->create(['reseller_id' => $resellerA->id]);
        Pelanggan::factory()->create(['reseller_id' => $resellerB->id]);

        Sanctum::actingAs($resellerA);

        $response = $this->getJson('/api/reseller/pendapatan/pelanggan-list');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $milikA->id);
    }

    public function test_reseller_bisa_unduh_laporan_pdf_dan_excel(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $this->buatPelangganBerbayar($reseller, 150000);

        Sanctum::actingAs($reseller);

        $pdf = $this->postJson('/api/reseller/pendapatan/report', [
            'tahun' => now()->year,
            'bulan' => [now()->month],
        ]);
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));

        $excel = $this->postJson('/api/reseller/pendapatan/report/excel', [
            'tahun' => now()->year,
            'bulan' => [now()->month],
        ]);
        $excel->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $excel->headers->get('Content-Type'),
        );
    }
}