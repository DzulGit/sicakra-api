<?php

namespace Tests\Feature\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filter status Tagihan Reseller harus menggunakan definisi status yang sama
 * dengan Admin Keuangan (TagihanFilter yang sama pada kedua controller).
 */
class TagihanListStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private PembayaranAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PembayaranAllocationService::class);
    }

    private function buatReseller(): Admin
    {
        return Admin::factory()->reseller()->create();
    }

    private function buatPelanggan(Admin $reseller): Pelanggan
    {
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);

        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        return $pelanggan->fresh('layananInternet');
    }

    private function buatTagihan(Pelanggan $pelanggan, int $total = 250000, ?array $periode = null): Tagihan
    {
        [$bulan, $tahun] = $periode ?? $this->periodeSekarang();

        return Tagihan::factory()->create([
            'layanan_internet_id' => $pelanggan->layananInternet->first()->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'total_tagihan' => $total,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    private function periodeSekarang(): array
    {
        $t = now('Asia/Jakarta');

        return [$t->month, $t->year];
    }

    public function test_filter_lunas(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $pelanggan = $this->buatPelanggan($reseller);
        $lunas = $this->buatTagihan($pelanggan, 250000);
        $this->service->buatPembayaranTunai($pelanggan, 250000, ['dibayar_oleh' => 'Reseller']);
        $this->buatTagihan($this->buatPelanggan($reseller), 250000);

        $json = $this->getJson('/api/reseller/tagihan?status=lunas')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->assertSame($lunas->id, $json->json('data.data.0.id'));
    }

    public function test_filter_lunas_memasukkan_kelebihan_pembayaran(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $pelanggan = $this->buatPelanggan($reseller);
        $lunas = $this->buatTagihan($pelanggan, 250000);
        $this->service->buatPembayaranTunai($pelanggan, 300000, ['dibayar_oleh' => 'Reseller']);

        $json = $this->getJson('/api/reseller/tagihan?status=lunas')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->assertSame($lunas->id, $json->json('data.data.0.id'));
        $this->assertSame(0, (int) $json->json('data.data.0.sisa'));
    }

    public function test_filter_belum_bayar_konsisten_dengan_keuangan(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $this->buatTagihan($this->buatPelanggan($reseller), 250000);
        $this->buatTagihan($this->buatPelanggan($reseller), 250000);

        $this->getJson('/api/reseller/tagihan?status=belum_bayar')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_filter_sedang_cicil_konsisten_dengan_keuangan(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $pelanggan = $this->buatPelanggan($reseller);
        $cicil = $this->buatTagihan($pelanggan, 250000);
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Reseller']);
        $this->buatTagihan($this->buatPelanggan($reseller), 250000);

        $json = $this->getJson('/api/reseller/tagihan?status=sedang_dicicil')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->assertSame($cicil->id, $json->json('data.data.0.id'));
    }

    public function test_filter_tertunggak_konsisten_dengan_keuangan(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $lampau = now('Asia/Jakarta')->subMonth();
        $this->buatTagihan($this->buatPelanggan($reseller), 250000, [$lampau->month, $lampau->year]);
        $this->buatTagihan($this->buatPelanggan($reseller), 250000);

        $this->getJson('/api/reseller/tagihan?status=tertunggak')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/reseller/tagihan?status=belum_bayar')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_reseller_hanya_melihat_scope_pelanggan_sendiri(): void
    {
        $reseller = $this->buatReseller();
        Sanctum::actingAs($reseller);

        $resellerLain = $this->buatReseller();

        $this->buatTagihan($this->buatPelanggan($reseller), 250000);
        $this->buatTagihan($this->buatPelanggan($resellerLain), 250000);

        $this->getJson('/api/reseller/tagihan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }
}