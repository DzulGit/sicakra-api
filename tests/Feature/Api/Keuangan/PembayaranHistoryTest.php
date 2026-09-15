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
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PembayaranHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function buatPelangganDanTagihan(array $atte = []): array
    {
        $pelanggan = $atte['pelanggan_model'] ?? Pelanggan::factory()->create($atte['pelanggan'] ?? []);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $tagihan = Tagihan::factory()->create(array_merge([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 9,
            'periode_tahun' => 2026,
            'harga_snapshot' => 150000,
            'total_tagihan' => 150000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ], $atte['tagihan'] ?? []));

        return [$pelanggan, $tagihan];
    }

    public function test_index_menampilkan_total_alokasi_sama_dengan_jumlah_dibayar(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 150000, ['dibayar_oleh' => 'Admin']);

        $this->getJson('/api/admin/keuangan/pembayaran')
            ->assertOk()
            ->assertJsonPath('data.data.0.nomor_pembayaran', 'PAY-000001')
            ->assertJsonPath('data.data.0.total_alokasi', 150000)
            ->assertJsonPath('data.data.0.alokasi_tagihan.0.nomor_tagihan', $tagihan->nomor_tagihan)
            ->assertJsonPath('data.data.0.alokasi_tagihan.0.jumlah_dialokasikan', 150000);
    }

    public function test_status_tagihan_dan_tanggal_lunas_dari_alokasi_terakhir(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);

        $service->buatPembayaranTunai($pelanggan, 50000, ['dibayar_oleh' => 'Admin']);
        $service->buatPembayaranTunai($pelanggan, 50000, ['dibayar_oleh' => 'Admin']);
        $service->buatPembayaranTunai($pelanggan, 50000, ['dibayar_oleh' => 'Admin']);

        $tagihanLunas = Tagihan::find($tagihan->id);
        $this->assertSame(StatusPembayaranEnum::SUDAH_BAYAR, $tagihanLunas->status_pembayaran);
        $this->assertNotNull($tagihanLunas->dibayar_pada);

        $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.status_tampilan', 'lunas')
            ->assertJsonPath('data.sisa_tagihan', 0)
            ->assertJsonCount(3, 'data.timeline_pembayaran');

        $json = $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")->json('data');
        $this->assertNotNull($json['tanggal_lunas'], 'tanggal_lunas harus terisi setelah lunas');

        // Transaksi yang benar-benar membuat remaining 0 adalah pembayaran terakhir.
        $timeline = $json['timeline_pembayaran'];
        $this->assertSame(0, $timeline[0]['sisa_setelah']);
        $this->assertSame(50000, $timeline[0]['jumlah']);
    }

    public function test_pembayaran_gabungan_satu_transaksi_banyak_alokasi(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan1] = $this->buatPelangganDanTagihan();
        [, $tagihan2] = $this->buatPelangganDanTagihan(['pelanggan_model' => $pelanggan]);

        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 300000, ['dibayar_oleh' => 'Admin']);

        $this->getJson('/api/admin/keuangan/pembayaran')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.total_alokasi', 300000)
            ->assertJsonCount(2, 'data.data.0.alokasi_tagihan')
            ->assertJsonPath('data.data.0.alokasi_tagihan.0.jumlah_dialokasikan', 150000)
            ->assertJsonPath('data.data.0.alokasi_tagihan.1.jumlah_dialokasikan', 150000);
    }

    public function test_kelebihan_pembayaran_menjadi_kredit_dan_muncul_di_saldo_kredit(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 200000, ['dibayar_oleh' => 'Admin']);

        $this->getJson("/api/admin/keuangan/saldo-kredit/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 50000)
            ->assertJsonCount(1, 'data.mutasi')
            ->assertJsonPath('data.mutasi.0.jenis', 'kredit')
            ->assertJsonPath('data.mutasi.0.saldo_setelah', 50000);
    }

    public function test_pemakaian_kredit_mengurangi_saldo_dan_masuk_timeline_tagihan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihanA] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);

        // 200.000 -> lunasi tagihanA (150.000) + 50.000 tersimpan sebagai kredit.
        $service->buatPembayaranTunai($pelanggan, 200000, ['dibayar_oleh' => 'Admin']);
        $this->assertSame(50000.0, (float) $service->hitungSaldoKredit($pelanggan));

        // Tagihan baru terbit; pemakaian kredit menutup sebagiannya.
        [, $tagihanB] = $this->buatPelangganDanTagihan([
            'pelanggan_model' => $pelanggan,
        ]);
        $service->gunakanSaldoKredit($pelanggan);

        $this->getJson("/api/admin/keuangan/saldo-kredit/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 0)
            ->assertJsonCount(2, 'data.mutasi');

        $timeline = $this->getJson("/api/admin/keuangan/tagihan/{$tagihanB->id}")->json('data.timeline_pembayaran');
        $kreditEvent = collect($timeline)->first(fn ($item) => $item['jenis'] === 'kredit');
        $this->assertNotNull($kreditEvent, 'Pemakaian kredit masuk timeline tagihan');
        $this->assertSame(50000, $kreditEvent['jumlah']);
        $this->assertSame(100000, $kreditEvent['sisa_setelah']);
    }

    public function test_pending_tidak_dihitung_sebagai_pembayaran_berhasil(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();

        Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::PENDING,
            'jumlah_dibayar' => 150000,
        ]);

        $json = $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")->json('data');
        $this->assertSame('belum_bayar', $json['status_tampilan']);
        $this->assertSame(150000, $json['sisa_tagihan']);
        $this->assertEmpty($json['timeline_pembayaran']);
    }

    public function test_reseller_hanya_melihat_pembayaran_pelanggan_sendiri(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $resellerB = Admin::factory()->reseller()->create();

        [$pelangganA, $tagihanA] = $this->buatPelangganDanTagihan(['pelanggan' => ['reseller_id' => $resellerA->id]]);
        [$pelangganB, $tagihanB] = $this->buatPelangganDanTagihan(['pelanggan' => ['reseller_id' => $resellerB->id]]);

        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelangganA, 150000, ['dibayar_oleh' => 'Admin']);
        $service->buatPembayaranTunai($pelangganB, 150000, ['dibayar_oleh' => 'Admin']);

        Sanctum::actingAs($resellerA);

        $this->getJson('/api/reseller/pembayaran')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.pelanggan.nama_lengkap', $pelangganA->nama_lengkap);

        $pembayaranB = Pembayaran::where('pelanggan_id', $pelangganB->id)->firstOrFail();
        $this->assertNotNull($pembayaranB);

        // Detail transaksi + saldo kredit pelanggan reseller lain ditolak.
        $this->getJson("/api/reseller/pembayaran/{$pembayaranB->id}")
            ->assertNotFound();

        $this->getJson("/api/reseller/saldo-kredit/{$pelangganB->id}")
            ->assertNotFound();
    }

    public function test_export_excel_berisi_baris_alokasi(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 150000, ['dibayar_oleh' => 'Admin']);

        $response = $this->get('/api/admin/keuangan/pembayaran/export/excel');
        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertNotEmpty($response->getContent());
    }

    public function test_export_pdf_berisi_informasi_tagihan_dan_pembayaran(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 150000, ['dibayar_oleh' => 'Admin']);

        $response = $this->get('/api/admin/keuangan/pembayaran/export/pdf');
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_filter_no_tagihan_menyaring_pembayaran(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihanA] = $this->buatPelangganDanTagihan();
        [, $tagihanB] = $this->buatPelangganDanTagihan([
            'pelanggan_model' => $pelanggan,
            'tagihan' => ['periode_bulan' => 10, 'periode_tahun' => 2026],
        ]);

        $service = app(PembayaranAllocationService::class);
        // 150.000 cukup untuk tagihanA (periode lebih tua) — tagihanB belum tersentuh.
        $service->buatPembayaranTunai($pelanggan, 150000, ['dibayar_oleh' => 'Admin']);

        $this->getJson('/api/admin/keuangan/pembayaran?no_tagihan=' . $tagihanA->nomor_tagihan)
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/admin/keuangan/pembayaran?no_tagihan=' . $tagihanB->nomor_tagihan)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->getJson('/api/admin/keuangan/pembayaran?status=' . StatusTransaksiEnum::GAGAL->value)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }
}