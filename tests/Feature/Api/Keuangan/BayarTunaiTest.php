<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
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

    private function buatAdminDanPelanggan(): array
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $bulanIni = now('Asia/Jakarta');
        $bulanDepan = now('Asia/Jakarta')->addMonth();
        $tagihan1 = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulanIni->month,
            'periode_tahun' => $bulanIni->year,
            'harga_snapshot' => 250000,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $tagihan2 = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulanDepan->month,
            'periode_tahun' => $bulanDepan->year,
            'harga_snapshot' => 250000,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        return [$pelanggan, $tagihan1, $tagihan2];
    }

    private function totalAlokasiTagihan(Tagihan $tagihan): float
    {
        return (float) PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_tagihan.pembayaran_id')
            ->where('pembayaran_tagihan.tagihan_id', $tagihan->id)
            ->where('pembayaran.status', StatusTransaksiEnum::BERHASIL->value)
            ->sum('jumlah_dialokasikan');
    }

    public function test_bayar_tunai_penuh_melunasi_tagihan(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 250000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(StatusPembayaranEnum::SUDAH_BAYAR, $tagihan1->fresh()->status_pembayaran);
    }

    public function test_bayar_tunai_sebagian_menjadi_cicilan(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(100000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(-150000.0, (float) $detail['sisa']);
        $this->assertSame('Sedang Cicil', $detail['status']);
        $this->assertSame(0.0, (float) $this->service->hitungSaldoKredit($pelanggan));
    }

    public function test_bayar_tunai_cicilan_kedua(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->service->buatPembayaranTunai($pelanggan, 100000, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => 'Admin',
            'tagihan_terpilih' => [$tagihan1->id],
        ]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(200000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(-50000.0, (float) $detail['sisa']);
        $this->assertSame('Sedang Cicil', $detail['status']);
    }

    public function test_bayar_tunai_cicilan_terakhir_menjadi_lunas(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->service->buatPembayaranTunai($pelanggan, 200000, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => 'Admin',
            'tagihan_terpilih' => [$tagihan1->id],
        ]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 50000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(0.0, (float) $this->service->hitungSaldoKredit($pelanggan));
    }

    public function test_kelebihan_pembayaran_menjadi_saldo_kredit(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 300000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(50000.0, (float) $this->service->hitungSaldoKredit($pelanggan));

        // Riwayat detail mengungkap pembayaran penuh + alokasi + saldo kredit.
        $this->getJson("/api/admin/keuangan/tagihan/{$tagihan1->id}")
            ->assertOk()
            ->assertJsonPath('data.timeline_pembayaran.0.jumlah_dibayar', 300000)
            ->assertJsonPath('data.timeline_pembayaran.0.jumlah', 250000)
            ->assertJsonPath('data.timeline_pembayaran.0.jumlah_kredit', 50000);
    }

    public function test_kelebihan_pembayaran_pada_tagihan_sudah_sebagian_dibayar(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->service->buatPembayaranTunai($pelanggan, 200000, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => 'Admin',
            'tagihan_terpilih' => [$tagihan1->id],
        ]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $detail = $this->service->detailTagihan($tagihan1);

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(0.0, (float) $detail['sisa']);
        $this->assertSame('Lunas', $detail['status']);
        $this->assertSame(50000.0, (float) $this->service->hitungSaldoKredit($pelanggan));
    }

    public function test_nominal_nol_ditolak(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 0,
        ])->assertUnprocessable();
    }

    public function test_nominal_negatif_ditolak(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => -50000,
        ])->assertUnprocessable();
    }

    public function test_tanpa_nominal_ditolak(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [])
            ->assertUnprocessable();
    }

    public function test_jumlah_bulan_tidak_lagi_diterima(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_bulan' => 2,
        ])->assertUnprocessable();
    }

    public function test_tagihan_sudah_lunas_tidak_dapat_dibayar(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->service->buatPembayaranTunai($pelanggan, 250000, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => 'Admin',
            'tagihan_terpilih' => [$tagihan1->id],
        ]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertStatus(422);
    }

    public function test_pembayaran_tunai_hanya_mengalokasikan_ke_tagihan_target(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 300000,
        ])->assertOk();

        $this->assertSame(250000.0, $this->totalAlokasiTagihan($tagihan1));
        $this->assertSame(0.0, $this->totalAlokasiTagihan($tagihan2));
        $this->assertSame(StatusPembayaranEnum::BELUM_BAYAR, $tagihan2->fresh()->status_pembayaran);
    }

    public function test_admin_keuangan_dapat_membayar_tagihan_scope_mana_pun(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        // Tagihan pelanggan lain tetap bisa dibayar admin keuangan.
        $pelangganLain = Pelanggan::factory()->create();
        $layananLain = LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganLain->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihanLain = Tagihan::factory()->create([
            'layanan_internet_id' => $layananLain->id,
            'periode_bulan' => 3,
            'periode_tahun' => 2026,
            'total_tagihan' => 100000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihanLain->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $this->assertSame(StatusPembayaranEnum::SUDAH_BAYAR, $tagihanLain->fresh()->status_pembayaran);
    }

    public function test_pembayaran_tidak_membuat_duplicate_allocation(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $this->assertSame(2, PembayaranTagihan::query()
            ->where('tagihan_id', $tagihan1->id)
            ->count());

        $detail = $this->service->detailTagihan($tagihan1);
        $this->assertSame(200000.0, (float) $detail['telah_terbayar']);
        $this->assertSame(-50000.0, (float) $detail['sisa']);
    }

    public function test_pembayaran_tercatat_berhasil_dengan_metode_tunai(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertOk();

        $pembayaran = \App\Models\Pembayaran::query()
            ->where('pelanggan_id', $pelanggan->id)
            ->first();

        $this->assertNotNull($pembayaran);
        $this->assertSame(StatusTransaksiEnum::BERHASIL, $pembayaran->status);
        $this->assertSame('tunai', $pembayaran->metode_pembayaran);
        $this->assertSame(100000.0, (float) $pembayaran->jumlah_dibayar);
        $this->assertSame([(int) $tagihan1->id], $pembayaran->tagihan_terpilih);
    }
}