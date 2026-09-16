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
use App\Models\PembayaranTagihan;
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
        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 150000,
            'dibayar_pada' => now(),
        ]);
        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihan->id,
            'jumlah_dialokasikan' => 150000,
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

    public function test_pelanggan_list_menyertakan_provinsi_dan_kota_per_pelanggan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create(['nama_lengkap' => 'A Sleman']);
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
            'provinsi' => 'Daerah Istimewa Yogyakarta',
            'kota' => 'Sleman',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/keuangan/pendapatan/pelanggan-list')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $pelanggan->id,
                'nama_lengkap' => 'A Sleman',
                'provinsi' => 'Daerah Istimewa Yogyakarta',
                'kota' => 'Sleman',
            ]);
    }

    public function test_pembayaran_gabungan_dihitung_sebagai_satu_transaksi(): void
    {
        $admin = Admin::factory()->keuangan()->create();

        $pelanggan = Pelanggan::factory()->create();

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $tagihanJanuari = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 150000,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
        ]);

        $bulanBerikutnya = now()->month === 12 ? 1 : now()->month + 1;
        $tahunBerikutnya = now()->month === 12 ? now()->year + 1 : now()->year;

        $tagihanFebruari = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 150000,
            'periode_bulan' => $bulanBerikutnya,
            'periode_tahun' => $tahunBerikutnya,
        ]);

        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 300000,
            'dibayar_pada' => now(),
        ]);

        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihanJanuari->id,
            'jumlah_dialokasikan' => 150000,
        ]);

        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihanFebruari->id,
            'jumlah_dialokasikan' => 150000,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/keuangan/pendapatan?'.http_build_query([
            'tahun' => now()->year,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.stats.total_pendapatan', 'Rp 300.000')
            ->assertJsonPath('data.stats.jumlah_pembayaran', 1)
            ->assertJsonCount(1, 'data.pembayaran_terbaru');

        $terbaru = $response->json('data.pembayaran_terbaru.0');

        $this->assertSame($pembayaran->id, $terbaru['id']);
        $this->assertSame('Rp 300.000', $terbaru['jumlah']);
        $this->assertStringContainsString(
            $tagihanJanuari->nomor_tagihan,
            $terbaru['nomor_tagihan']
        );
    }

    public function test_report_excel_ringkasan_adalah_timeline_pelanggan_per_bulan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $this->buatFixtureTimeline();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/keuangan/pendapatan/report/excel', [
            'tahun' => 2026,
            'bulan' => [9],
        ]);
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'laporan').'.xlsx';
        file_put_contents($tmp, $response->getContent());

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);

            // J: export tetap 4 sheet.
            $this->assertSame(
                ['Ringkasan Tagihan', 'Transaksi Pembayaran', 'Alokasi Tagihan', 'Saldo Kredit'],
                $spreadsheet->getSheetNames()
            );

            $rows = array_slice($spreadsheet->getSheetByName('Ringkasan Tagihan')->toArray(), 3);
            $map = [];

            foreach ($rows as $r) {
                $map[$r[3].'|'.$r[4]] = [
                    'no_tagihan' => $r[1],
                    'total' => (float) $r[5],
                    'dibayar' => (float) $r[6],
                    'kredit' => (float) $r[7],
                    'terbayar' => (float) $r[8],
                    'sisa' => (float) $r[9],
                    'status' => $r[10],
                    'tgl_lunas' => $r[11],
                ];
            }

            // Setiap pelanggan punya baris untuk setiap bulan (Januari..September).
            $this->assertCount(7 * 9, $map);

            // A + D: Budi mulai September; Januari..Agustus belum berlangganan, September dicicil.
            $this->assertSame('Belum Berlangganan', $map['Budi|Agustus 2026']['status']);
            $this->assertEmpty($map['Budi|Agustus 2026']['no_tagihan']);
            $this->assertSame('Sedang Dicicil', $map['Budi|September 2026']['status']);
            $this->assertSame(350000.0, $map['Budi|September 2026']['total']);
            $this->assertSame(150000.0, $map['Budi|September 2026']['dibayar']);
            $this->assertSame(200000.0, $map['Budi|September 2026']['sisa']);

            // B: Citra aktif sejak Mei tapi tidak punya tagihan.
            $this->assertSame('Belum Berlangganan', $map['Citra|April 2026']['status']);
            $this->assertSame('Belum Ada Tagihan', $map['Citra|Mei 2026']['status']);
            $this->assertSame(0.0, $map['Citra|Mei 2026']['sisa']);

            // C: Dedi punya tagihan Juni tanpa pembayaran; runtutan Nunggak menyusul.
            $this->assertSame('Belum Bayar', $map['Dedi|Juni 2026']['status']);
            $this->assertSame(150000.0, $map['Dedi|Juni 2026']['sisa']);
            $this->assertSame('Nunggak', $map['Dedi|Agustus 2026']['status']);

            // E: Eka lunas dan tanggal lunas tampil.
            $this->assertSame('Lunas', $map['Eka|April 2026']['status']);
            $this->assertSame(200000.0, $map['Eka|April 2026']['total']);
            $this->assertSame(0.0, $map['Eka|April 2026']['sisa']);
            $this->assertSame('25 April 2026 14:32:18', $map['Eka|April 2026']['tgl_lunas']);

            // F: Fajar Juli belum bayar; Agustus/September jadi Nunggak (tagihan bulan sebelum belum lunas).
            $this->assertSame('Belum Bayar', $map['Fajar|Juli 2026']['status']);
            $this->assertSame('Nunggak', $map['Fajar|Agustus 2026']['status']);
            $this->assertSame('Nunggak', $map['Fajar|September 2026']['status']);

            // G: pembayaran gabungan tidak dobel — dua tagihan lunas terpisah.
            $this->assertSame('Lunas', $map['Gabriel|Januari 2026']['status']);
            $this->assertSame(150000.0, $map['Gabriel|Januari 2026']['dibayar']);
            $this->assertSame('Lunas', $map['Gabriel|Februari 2026']['status']);

            // H: overpay jadi deposit, tidak dihitung sebagai pendapatan baru; pemakaian kredit tampil.
            $this->assertSame('Lunas', $map['Hana|Mei 2026']['status']);
            $this->assertSame(350000.0, $map['Hana|Mei 2026']['dibayar']);
            $this->assertSame('Lunas', $map['Hana|Juni 2026']['status']);
            $this->assertSame(150000.0, $map['Hana|Juni 2026']['dibayar']);
            $this->assertSame(50000.0, $map['Hana|Juni 2026']['kredit']);
            $this->assertSame(0.0, $map['Hana|Juni 2026']['sisa']);

            // Numerik tetap numerik di Excel (bukan string "Rp ...").
            $this->assertIsFloat($map['Budi|September 2026']['total']);
        } finally {
            @unlink($tmp);
        }
    }

    private function buatLayanan(int $pelangganId, string $mulai): LayananInternet
    {
        return LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganId,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => $mulai,
            'tanggal_mulai_penagihan' => $mulai,
        ]);
    }

    private function buatTagihan(
        LayananInternet $layanan,
        int $bulan,
        float $total,
        ?string $dibayarPada = null,
        StatusPembayaranEnum $status = StatusPembayaranEnum::BELUM_BAYAR,
    ): Tagihan {
        return Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => 2026,
            'total_tagihan' => $total,
            'dibayar_pada' => $dibayarPada,
            'status_pembayaran' => $status,
        ]);
    }

    private function bayarTagihan(Pelanggan $pelanggan, string $waktu, array $alokasi): void
    {
        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => array_sum(array_column($alokasi, 'jumlah')),
            'dibayar_pada' => $waktu,
        ]);

        foreach ($alokasi as $a) {
            PembayaranTagihan::create([
                'pembayaran_id' => $pembayaran->id,
                'tagihan_id' => $a['tagihan']->id,
                'jumlah_dialokasikan' => $a['jumlah'],
            ]);
        }
    }

    private function buatFixtureTimeline(): void
    {
        // A + D: Budi mulai September, bayar sebagian.
        $budi = Pelanggan::factory()->create(['nama_lengkap' => 'Budi']);
        $tagihanBudi = $this->buatTagihan($this->buatLayanan($budi->id, '2026-09-01'), 9, 350000);
        $this->bayarTagihan($budi, '2026-09-10 02:00:00', [['tagihan' => $tagihanBudi, 'jumlah' => 150000]]);

        // B: Citra aktif sejak Mei tapi tidak ada tagihan.
        $citra = Pelanggan::factory()->create(['nama_lengkap' => 'Citra']);
        $this->buatLayanan($citra->id, '2026-05-01');

        // C: Dedi tagihan Juni tanpa bayar.
        $dedi = Pelanggan::factory()->create(['nama_lengkap' => 'Dedi']);
        $this->buatTagihan($this->buatLayanan($dedi->id, '2026-01-01'), 6, 150000);

        // E: Eka April lunas dengan tanggal lunas.
        $eka = Pelanggan::factory()->create(['nama_lengkap' => 'Eka']);
        $tagihanEka = $this->buatTagihan(
            $this->buatLayanan($eka->id, '2026-01-01'),
            4,
            200000,
            dibayarPada: '2026-04-25 07:32:18',
            status: StatusPembayaranEnum::SUDAH_BAYAR,
        );
        $this->bayarTagihan($eka, '2026-04-25 07:32:18', [['tagihan' => $tagihanEka, 'jumlah' => 200000]]);

        // F: Fajar Juli & Agustus belum bayar -> nunggak setelahnya.
        $fajar = Pelanggan::factory()->create(['nama_lengkap' => 'Fajar']);
        $layananFajar = $this->buatLayanan($fajar->id, '2026-01-01');
        $this->buatTagihan($layananFajar, 7, 150000);
        $this->buatTagihan($layananFajar, 8, 150000);

        // G: Gabriel bayar gabungan Januari & Februari.
        $gabriel = Pelanggan::factory()->create(['nama_lengkap' => 'Gabriel']);
        $layananGabriel = $this->buatLayanan($gabriel->id, '2026-01-01');
        $tagihanJanuari = $this->buatTagihan($layananGabriel, 1, 150000);
        $tagihanFebruari = $this->buatTagihan($layananGabriel, 2, 150000);
        $this->bayarTagihan($gabriel, '2026-02-03 08:00:00', [
            ['tagihan' => $tagihanJanuari, 'jumlah' => 150000],
            ['tagihan' => $tagihanFebruari, 'jumlah' => 150000],
        ]);

        // H: Hana overpay Mei jadi deposit 50.000, dipakai Juni.
        $hana = Pelanggan::factory()->create(['nama_lengkap' => 'Hana']);
        $layananHana = $this->buatLayanan($hana->id, '2026-01-01');
        $tagihanMei = $this->buatTagihan($layananHana, 5, 350000);
        $tagihanJuni = $this->buatTagihan($layananHana, 6, 200000);
        $this->bayarTagihan($hana, '2026-05-15 02:00:00', [['tagihan' => $tagihanMei, 'jumlah' => 350000]]);
        MutasiSaldoKredit::create([
            'pelanggan_id' => $hana->id,
            'pembayaran_id' => null,
            'tagihan_id' => null,
            'jenis' => 'kredit',
            'jumlah' => 50000,
            'keterangan' => 'Kelebihan pembayaran menjadi deposit.',
        ]);
        $this->bayarTagihan($hana, '2026-06-20 04:00:00', [['tagihan' => $tagihanJuni, 'jumlah' => 150000]]);
        MutasiSaldoKredit::create([
            'pelanggan_id' => $hana->id,
            'pembayaran_id' => null,
            'tagihan_id' => $tagihanJuni->id,
            'jenis' => 'pemakaian',
            'jumlah' => 50000,
            'keterangan' => 'Saldo kredit digunakan untuk tagihan.',
        ]);
    }
}
