<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagihanFinanceStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PembayaranAllocationService::class);
    }

    private function buatPelangganAktif(): Pelanggan
    {
        $pelanggan = Pelanggan::factory()->create();

        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        return $pelanggan->fresh('layananInternet');
    }

    private function buatTagihan(
        Pelanggan $pelanggan,
        int $total = 250000,
        ?array $periode = null,
    ): Tagihan {
        [$bulan, $tahun] = $periode ?? $this->periodeSekarang();

        $layanan = $pelanggan->layananInternet->first();

        return Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'harga_snapshot' => $total,
            'total_tagihan' => $total,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    private function periodeSekarang(): array
    {
        $t = now('Asia/Jakarta');

        return [$t->month, $t->year];
    }

    private function periodeLampau(): array
    {
        $t = now('Asia/Jakarta')->subMonth();

        return [$t->month, $t->year];
    }

    public function test_tagihan_tanpa_pembayaran_adalah_belum_bayar(): void
    {
        [$admin, $pelanggan, $tagihan] = $this->setupAdmin();

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(0.0, (float) $detail['telah_terbayar']);
        $this->assertSame(-250000.0, (float) $detail['sisa']);
        $this->assertSame('Belum Bayar', $detail['status']);
        $this->assertSame('belum_bayar', $detail['status_tampilan']);

        $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'Belum Bayar')
            ->assertJsonPath('data.telah_terbayar', 0)
            ->assertJsonPath('data.sisa', -250000);
    }

    public function test_pembayaran_sebagian_adalah_sedang_cicil_dengan_sisa_negatif(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(100000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(-150000.0, (float) $detail['sisa']);
        $this->assertSame('Sedang Cicil', $detail['status']);
    }

    public function test_pembayaran_tepat_lunas_adalah_lunas_dengan_sisa_nol(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 250000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(250000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
    }

    public function test_kelebihan_pembayaran_menjadi_kredit_dan_tagihan_lunas(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 300000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        // Alokasi maksimal sesuai total tagihan; kelebihan tersimpan sebagai kredit pelanggan.
        $this->assertSame(250000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(50000.0, (float) $this->service->hitungSaldoKredit($pelanggan));
    }

    public function test_periode_lampau_dengan_kekurangan_dan_layanan_aktif_adalah_tertunggak(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000, $this->periodeLampau());
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(-150000.0, (float) $detail['sisa']);
        $this->assertSame('Tertunggak', $detail['status']);
        $this->assertSame('sedang_dicicil', $detail['status_tampilan'], 'status_tampilan lama tetap menunjukkan cicilan');
    }

    public function test_periode_lampau_dengan_kekurangan_tapi_layanan_nonaktif_adalah_sedang_cicil(): void
    {
        $this->setupAdmin();

        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::NONAKTIF,
        ]);
        [$bulan, $tahun] = $this->periodeLampau();
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame('Sedang Cicil', $detail['status']);
    }

    public function test_periode_sekarang_dengan_kekurangan_belum_dianggap_tertunggak(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(-150000.0, (float) $detail['sisa']);
        $this->assertSame('Sedang Cicil', $detail['status']);
    }

    public function test_deposit_menutup_tagihan_adalah_lunas(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 250000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $this->service->gunakanSaldoKredit($pelanggan);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(250000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
    }

    public function test_pembayaran_bank_plus_deposit_dihitung_menjadi_telah_terbayar(): void
    {
        $this->setupAdmin();

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan(250000);

        // 100.000 bayar langsung, 150.000 menutup via saldo kredit.
        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 150000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $this->service->gunakanSaldoKredit($pelanggan);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(250000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
    }

    public function test_detail_tagihan_menampilkan_telah_terbayar_dan_status(): void
    {
        [$admin, $pelanggan, $tagihan] = $this->setupAdmin();

        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.telah_terbayar', 100000)
            ->assertJsonPath('data.sisa', -150000)
            ->assertJsonPath('data.status', 'Sedang Cicil')
            ->assertJsonPath('data.status_tampilan', 'sedang_dicicil')
            ->assertJsonPath('data.sisa_tagihan', 150000);
    }

    public function test_index_menyediakan_telah_terbayar_sisa_status_dan_tidak_menampilkan_draft(): void
    {
        [$admin, $pelanggan, $tagihan] = $this->setupAdmin();

        // Draft tidak boleh tampil di list utama (periode berbeda agar tak bentrok unique).
        $berikut = now('Asia/Jakarta')->addMonth();
        Tagihan::factory()->create([
            'layanan_internet_id' => $tagihan->layanan_internet_id,
            'periode_bulan' => $berikut->month,
            'periode_tahun' => $berikut->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        $this->service->buatPembayaranTunai($pelanggan, 100000, ['dibayar_oleh' => 'Admin']);

        $json = $this->getJson('/api/admin/keuangan/tagihan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $item = $json->json('data.data.0');
        $this->assertSame(1, $json->json('data.total'));
        $this->assertSame(100000, $item['telah_terbayar']);
        $this->assertSame(-150000, $item['sisa']);
        $this->assertSame('Sedang Cicil', $item['status']);
    }

    public function test_pending_tidak_dihitung_sebagai_terbayar(): void
    {
        [$admin, $pelanggan, $tagihan] = $this->setupAdmin();

        Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::PENDING,
            'jumlah_dibayar' => 150000,
        ]);

        $detail = $this->service->detailTagihan($tagihan);

        $this->assertSame(0.0, (float) $detail['telah_terbayar']);
        $this->assertSame('Belum Bayar', $detail['status']);
        $this->assertSame(-250000.0, (float) $detail['sisa']);
    }

    private function setupAdmin(): array
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $pelanggan = $this->buatPelangganAktif();
        $tagihan = $this->buatTagihan($pelanggan);

        return [$admin, $pelanggan, $tagihan];
    }

    private function buatPelangganDanTagihan(int $total = 250000, ?array $periode = null): array
    {
        $pelanggan = $this->buatPelangganAktif();
        $tagihan = $this->buatTagihan($pelanggan, $total, $periode);

        return [$pelanggan, $tagihan];
    }
}