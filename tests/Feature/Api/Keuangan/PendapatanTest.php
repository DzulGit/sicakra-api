<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_report_excel_memuat_detail_pelanggan_dan_paket(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->sudahAktif()->create([
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '3201010203040506',
            'nomor_hp' => '081234567890',
        ]);
        $paket = PaketInternet::factory()->create([
            'nama_paket' => 'Family 25 Mbps',
            'kecepatan_mbps' => 25,
        ]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'alamat_pemasangan' => 'Jl. Merdeka No. 1, Yogyakarta',
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'nomor_tagihan' => 'INV000123',
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'nama_paket_snapshot' => 'Family 25 Mbps',
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 150000,
        ]);
        Pembayaran::factory()->create([
            'tagihan_id' => $tagihan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 150000,
            'metode_pembayaran' => 'BCA',
            'dibayar_pada' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/admin/keuangan/pendapatan/report/excel?'.http_build_query([
            'tahun' => now()->year,
            'bulan' => now()->month,
        ]));
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'pendapatan-');
        file_put_contents($tmp, $response->getContent());
        $bukuKerja = IOFactory::load($tmp);
        @unlink($tmp);
        $sheet = $bukuKerja->getActiveSheet();

        $this->assertSame('Budi Santoso', $sheet->getCell('F8')->getValue());
        $this->assertSame('3201010203040506', $sheet->getCell('G8')->getValue());
        $this->assertSame('081234567890', $sheet->getCell('H8')->getValue());
        $this->assertSame('Family 25 Mbps', $sheet->getCell('K8')->getValue());
        $this->assertSame('25 Mbps', $sheet->getCell('L8')->getValue());
        $this->assertSame('INV000123', $sheet->getCell('C8')->getValue());
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
}
