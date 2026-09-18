<?php

namespace Tests\Feature\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BayarTunaiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->service = app(PembayaranAllocationService::class);
    }

    private function buatResellerDenganPelanggan(): array
    {
        $reseller = Admin::factory()->reseller()->create();
        Sanctum::actingAs($reseller);

        $pelanggan = Pelanggan::factory()->create([
            'reseller_id' => $reseller->id,
        ]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $periodeIni = now('Asia/Jakarta');
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $periodeIni->month,
            'periode_tahun' => $periodeIni->year,
            'harga_snapshot' => 250000,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        return [$reseller, $pelanggan, $tagihan];
    }

    private function totalAlokasiTagihan(Tagihan $tagihan): float
    {
        return (float) PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_tagihan.pembayaran_id')
            ->where('pembayaran_tagihan.tagihan_id', $tagihan->id)
            ->sum('jumlah_dialokasikan');
    }

    public function test_reseller_bayar_tunai_sebagian_tagihan_pelanggan_sendiri(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelanggan();

        $this->postJson("/api/reseller/tagihan/{$tagihan->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(100000.0, $this->totalAlokasiTagihan($tagihan));
        $this->assertSame(-150000.0, (float) $detail['sisa']);
        $this->assertSame('Sedang Cicil', $detail['status']);
    }

    public function test_reseller_bayar_tunai_overpayment_menjadi_kredit(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelanggan();

        $this->postJson("/api/reseller/tagihan/{$tagihan->id}/bayar-tunai", [
            'jumlah_dibayar' => 300000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan));
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(50000.0, (float) $this->service->hitungSaldoKredit($pelanggan));
    }

    public function test_reseller_tidak_dapat_membayar_tagihan_reseller_lain(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelanggan();

        $resellerLain = Admin::factory()->reseller()->create();
        $pelangganLain = Pelanggan::factory()->create([
            'reseller_id' => $resellerLain->id,
        ]);
        $layananLain = LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganLain->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihanLain = Tagihan::factory()->create([
            'layanan_internet_id' => $layananLain->id,
            'periode_bulan' => 2,
            'periode_tahun' => 2026,
            'total_tagihan' => 150000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        // Tetap login sebagai reseller pertama.
        Sanctum::actingAs($reseller);

        $this->postJson("/api/reseller/tagihan/{$tagihanLain->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertNotFound();

        $this->assertSame(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihanLain->fresh()->status_pembayaran,
        );
    }

    public function test_reseller_hanya_mengalokasikan_ke_tagihan_targetnya(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelanggan();

        $tagihanKedua = Tagihan::factory()->create([
            'layanan_internet_id' => $tagihan->layanan_internet_id,
            'periode_bulan' => now('Asia/Jakarta')->addMonth()->month,
            'periode_tahun' => now('Asia/Jakarta')->addMonth()->year,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $this->postJson("/api/reseller/tagihan/{$tagihan->id}/bayar-tunai", [
            'jumlah_dibayar' => 300000,
        ])->assertOk();

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan));
        $this->assertSame(0.0, $this->totalAlokasiTagihan($tagihanKedua));
        $this->assertSame(StatusPembayaranEnum::BELUM_BAYAR, $tagihanKedua->fresh()->status_pembayaran);
    }

    public function test_reseller_nominal_tidak_berisi_ditolak(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelanggan();

        $this->postJson("/api/reseller/tagihan/{$tagihan->id}/bayar-tunai", [])
            ->assertUnprocessable();
    }
}