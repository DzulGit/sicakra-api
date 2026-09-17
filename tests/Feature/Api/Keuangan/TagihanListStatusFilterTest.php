<?php

namespace Tests\Feature\Api\Keuangan;

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

class TagihanListStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private PembayaranAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PembayaranAllocationService::class);
    }

    /** Tagihan periode sekarang, status BELUM_BAYAR, layanan AKTIF. */
    private function buatTagihan(int $total = 250000, ?array $periode = null): array
    {
        $pelanggan = Pelanggan::factory()->create();
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        [$bulan, $tahun] = $periode ?? self::periodeSekarang();

        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $pelanggan->fresh('layananInternet')->layananInternet->first()->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'total_tagihan' => $total,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        return [$pelanggan, $tagihan];
    }

    private static function periodeSekarang(): array
    {
        $t = now('Asia/Jakarta');

        return [$t->month, $t->year];
    }

    public function test_filter_lunas(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $lunas] = $this->buatTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 250000, ['dibayar_oleh' => 'Admin']);
        $this->buatTagihan(250000);

        $json = $this->getJson('/api/admin/keuangan/tagihan?status=lunas')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->assertSame($lunas->id, $json->json('data.data.0.id'));
    }

    public function test_filter_belum_bayar(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->buatTagihan(250000);
        $this->buatTagihan(250000);

        $this->getJson('/api/admin/keuangan/tagihan?status=belum_bayar')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_filter_tertunggak_dan_belum_bayar_dibedakan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        // Tertunggak: periode lampau, belum dibayar, layanan aktif.
        $lampau = now('Asia/Jakarta')->subMonth();
        $this->buatTagihan(250000, [$lampau->month, $lampau->year]);
        // Belum bayar: periode sekarang, belum dibayar.
        $this->buatTagihan(250000);

        $this->getJson('/api/admin/keuangan/tagihan?status=tertunggak')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/admin/keuangan/tagihan?status=belum_bayar')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_filter_sedang_cicil(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $cicil] = $this->buatTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $this->buatTagihan(250000);

        $json = $this->getJson('/api/admin/keuangan/tagihan?status=sedang_dicicil')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->assertSame($cicil->id, $json->json('data.data.0.id'));
    }

    public function test_param_lama_status_pembayaran_diabaikan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $cicil] = $this->buatTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);
        $this->buatTagihan(250000);

        $this->getJson('/api/admin/keuangan/tagihan?status_pembayaran=belum_bayar&status=sedang_dicicil')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }
}