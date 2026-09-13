<?php

namespace Tests\Unit\Services;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PembayaranAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        parent::setUp();
    }

    private function buatPelangganDenganLayanan(): array
    {
        $pelanggan = Pelanggan::factory()->create();

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        return [$pelanggan, $layanan];
    }

    private function buatTagihan(
        LayananInternet $layanan,
        int $bulan,
        int $tahun,
        int $total = 100000,
        StatusPembayaranEnum $status = StatusPembayaranEnum::BELUM_BAYAR,
    ): Tagihan {
        return Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'total_tagihan' => $total,
            'harga_snapshot' => $total,
            'status_pembayaran' => $status,
        ]);
    }

    public function test_pembayaran_penuh_melunasi_tagihan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 1, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan->status_pembayaran
        );

        $this->assertEquals(
            100000,
            (float) PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
                ->where('tagihan_id', $tagihan->id)
                ->value('jumlah_dialokasikan')
        );
    }

    public function test_pembayaran_sebagian_tidak_mengubah_total_tagihan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 1, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 50000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $tagihan->refresh();

        $this->assertEquals(100000, (float) $tagihan->total_tagihan);

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan->status_pembayaran
        );

        $this->assertEquals(
            50000,
            (float) PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
                ->where('tagihan_id', $tagihan->id)
                ->value('jumlah_dialokasikan')
        );
    }

    public function test_pembayaran_kedua_melunasi_sisa_tagihan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 1, 2026, 100000);

        $pembayaranPertama = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 50000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service = app(PembayaranAllocationService::class);

        $service->selesaikanPembayaran($pembayaranPertama);

        $pembayaranKedua = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 50000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service->selesaikanPembayaran($pembayaranKedua);

        $tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan->status_pembayaran
        );

        $this->assertEquals(
            100000,
            (float) PembayaranTagihan::where('tagihan_id', $tagihan->id)
                ->sum('jumlah_dialokasikan')
        );
    }

    public function test_pembayaran_gabungan_mengalokasikan_dari_tagihan_tertua(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $mei = $this->buatTagihan($layanan, 5, 2026, 100000);
        $juni = $this->buatTagihan($layanan, 6, 2026, 100000);
        $juli = $this->buatTagihan($layanan, 7, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 150000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $mei->refresh();
        $juni->refresh();
        $juli->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $mei->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $juni->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $juli->status_pembayaran
        );

        $this->assertEquals(
            100000,
            (float) PembayaranTagihan::where('tagihan_id', $mei->id)
                ->sum('jumlah_dialokasikan')
        );

        $this->assertEquals(
            50000,
            (float) PembayaranTagihan::where('tagihan_id', $juni->id)
                ->sum('jumlah_dialokasikan')
        );

        $this->assertEquals(
            0,
            (float) PembayaranTagihan::where('tagihan_id', $juli->id)
                ->sum('jumlah_dialokasikan')
        );
    }

    public function test_pembayaran_berikutnya_melanjutkan_sisa_tagihan_tertua(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $mei = $this->buatTagihan($layanan, 5, 2026, 100000);
        $juni = $this->buatTagihan($layanan, 6, 2026, 100000);
        $juli = $this->buatTagihan($layanan, 7, 2026, 100000);

        $service = app(PembayaranAllocationService::class);

        $pembayaranPertama = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 150000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service->selesaikanPembayaran($pembayaranPertama);

        $pembayaranKedua = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 75000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service->selesaikanPembayaran($pembayaranKedua);

        $mei->refresh();
        $juni->refresh();
        $juli->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $mei->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $juni->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $juli->status_pembayaran
        );

        $this->assertEquals(
            25000,
            (float) PembayaranTagihan::where('tagihan_id', $juli->id)
                ->sum('jumlah_dialokasikan')
        );
    }

    public function test_overpayment_menjadi_saldo_kredit_pelanggan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 5, 2026, 150000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 200000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan->status_pembayaran
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'kredit')
                ->sum('jumlah')
        );

        $service = app(PembayaranAllocationService::class);

        $this->assertEquals(
            50000,
            $service->hitungSaldoKredit($pelanggan)
        );
    }

    public function test_saldo_kredit_digunakan_untuk_tagihan_tertua(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 5, 2026, 100000);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'pembayaran_id' => null,
            'tagihan_id' => null,
            'jenis' => 'kredit',
            'jumlah' => 50000,
            'keterangan' => 'Saldo kredit test',
        ]);

        app(PembayaranAllocationService::class)->gunakanSaldoKredit($pelanggan);

        $tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan->status_pembayaran
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('tagihan_id', $tagihan->id)
                ->where('jenis', 'pemakaian')
                ->sum('jumlah')
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'pemakaian')
                ->sum('jumlah')
        );

        $this->assertEquals(
            0,
            app(PembayaranAllocationService::class)->hitungSaldoKredit($pelanggan)
        );
    }

    public function test_tagihan_belum_diterbitkan_tidak_ikut_dialokasikan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $belumDiterbitkan = $this->buatTagihan(
            $layanan,
            5,
            2026,
            100000,
            StatusPembayaranEnum::BELUM_DITERBITKAN
        );

        $belumBayar = $this->buatTagihan(
            $layanan,
            6,
            2026,
            100000,
            StatusPembayaranEnum::BELUM_BAYAR
        );

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $belumDiterbitkan->refresh();
        $belumBayar->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_DITERBITKAN,
            $belumDiterbitkan->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $belumBayar->status_pembayaran
        );

        $this->assertEquals(
            0,
            (float) PembayaranTagihan::where('tagihan_id', $belumDiterbitkan->id)
                ->sum('jumlah_dialokasikan')
        );
    }

    public function test_pembayaran_gagal_tidak_dialokasikan(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 5, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'status' => StatusTransaksiEnum::GAGAL,
        ]);

        $this->expectException(\RuntimeException::class);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $this->assertDatabaseMissing('pembayaran_tagihan', [
            'pembayaran_id' => $pembayaran->id,
        ]);

        $this->assertDatabaseHas('tagihan', [
            'id' => $tagihan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR->value,
        ]);
    }

    public function test_pembayaran_yang_sama_tidak_dialokasikan_dua_kali(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 5, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service = app(PembayaranAllocationService::class);

        $service->selesaikanPembayaran($pembayaran);
        $service->selesaikanPembayaran($pembayaran);

        $this->assertEquals(
            1,
            PembayaranTagihan::where('pembayaran_id', $pembayaran->id)->count()
        );

        $this->assertEquals(
            100000,
            (float) PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
                ->sum('jumlah_dialokasikan')
        );

        $this->assertEquals(
            0,
            MutasiSaldoKredit::where('pembayaran_id', $pembayaran->id)
                ->count()
        );

        $tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan->status_pembayaran
        );
    }

    public function test_selesaikan_pembayaran_hanya_mengalokasikan_tagihan_terpilih(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan1 = $this->buatTagihan($layanan, 1, 2026, 100000);
        $tagihan2 = $this->buatTagihan($layanan, 2, 2026, 100000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'tagihan_terpilih' => [$tagihan2->id],
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $this->assertEquals(
            100000,
            (float) PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
                ->where('tagihan_id', $tagihan2->id)
                ->value('jumlah_dialokasikan')
        );

        $this->assertEquals(
            0,
            PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
                ->where('tagihan_id', $tagihan1->id)
                ->count()
        );

        $tagihan1->refresh();
        $tagihan2->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan1->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan2->status_pembayaran
        );
    }

    public function test_ringkasan_tunggakan_menghitung_sisa_bukan_total(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan1 = $this->buatTagihan($layanan, 1, 2026, 100000);
        $tagihan2 = $this->buatTagihan($layanan, 2, 2026, 200000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 40000,
            'tagihan_terpilih' => [$tagihan1->id],
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(PembayaranAllocationService::class)->selesaikanPembayaran($pembayaran);

        $ringkasan = app(PembayaranAllocationService::class)
            ->ringkasanTunggakan($pelanggan);

        $this->assertSame(2, $ringkasan['jumlah_tagihan']);
        $this->assertSame(260000.0, $ringkasan['total_tunggakan']);

        $detail = collect($ringkasan['tagihan'])->keyBy('id');

        $this->assertSame(60000.0, $detail[$tagihan1->id]['sisa_tagihan']);
        $this->assertSame(40000.0, $detail[$tagihan1->id]['sudah_dibayar']);
        $this->assertSame(200000.0, $detail[$tagihan2->id]['sisa_tagihan']);
    }

    public function test_ringkasan_tunggakan_mengurangi_saldo_kredit_terpakai(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan = $this->buatTagihan($layanan, 1, 2026, 100000);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => $tagihan->id,
            'jenis' => 'pemakaian',
            'jumlah' => 30000,
            'keterangan' => 'Saldo deposit dipakai.',
        ]);

        $ringkasan = app(PembayaranAllocationService::class)
            ->ringkasanTunggakan($pelanggan);

        $this->assertSame(1, $ringkasan['jumlah_tagihan']);
        $this->assertSame(70000.0, $ringkasan['total_tunggakan']);

        $detail = $ringkasan['tagihan'][0];

        $this->assertSame(30000.0, $detail['saldo_kredit_digunakan']);
        $this->assertSame(70000.0, $detail['sisa_tagihan']);
    }

    public function test_gunakan_saldo_kredit_dan_selesaikan_pembayaran_bersama_tidak_double_apply(): void
    {
        [$pelanggan, $layanan] = $this->buatPelangganDenganLayanan();

        $tagihan1 = $this->buatTagihan($layanan, 1, 2026, 100000);
        $tagihan2 = $this->buatTagihan($layanan, 2, 2026, 100000);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'jenis' => 'kredit',
            'jumlah' => 50000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $service = app(PembayaranAllocationService::class);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 100000,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $service->selesaikanPembayaran($pembayaran);
        $service->gunakanSaldoKredit($pelanggan);

        $tagihan1->refresh();
        $tagihan2->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan1->status_pembayaran
        );

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan2->status_pembayaran
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'kredit')
                ->sum('jumlah')
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'pemakaian')
                ->sum('jumlah')
        );

        $this->assertEquals(
            50000.0,
            app(PembayaranAllocationService::class)
                ->hitungSisaTagihan($tagihan2)
        );

        $this->assertEquals(
            0.0,
            app(PembayaranAllocationService::class)
                ->hitungSaldoKredit($pelanggan)
        );
    }
}
