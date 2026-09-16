<?php

namespace Tests\Feature\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
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
        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => $tagihan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => $nominal,
            'dibayar_pada' => now(),
        ]);
        PembayaranTagihan::create([
            "pembayaran_id" => $pembayaran->id,
            "tagihan_id" => $tagihan->id,
            "jumlah_dialokasikan" => $nominal,
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

    public function test_reseller_ringkasan_timeline_hanya_berisi_pelanggan_miliknya(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $resellerB = Admin::factory()->reseller()->create();

        $pelangganA = Pelanggan::factory()->create([
            'reseller_id' => $resellerA->id,
            'nama_lengkap' => 'Milik Reseller A',
        ]);
        $layananA = LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganA->id,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-01',
            'tanggal_mulai_penagihan' => '2026-01-01',
        ]);
        Tagihan::factory()->create([
            'layanan_internet_id' => $layananA->id,
            'periode_bulan' => 9,
            'periode_tahun' => 2026,
            'total_tagihan' => 150000,
        ]);

        $pelangganB = Pelanggan::factory()->create([
            'reseller_id' => $resellerB->id,
            'nama_lengkap' => 'Milik Reseller B',
        ]);
        $layananB = LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganB->id,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-01',
            'tanggal_mulai_penagihan' => '2026-01-01',
        ]);
        Tagihan::factory()->create([
            'layanan_internet_id' => $layananB->id,
            'periode_bulan' => 9,
            'periode_tahun' => 2026,
            'total_tagihan' => 999000,
        ]);

        Sanctum::actingAs($resellerA);

        $response = $this->postJson('/api/reseller/pendapatan/report/excel', [
            'tahun' => 2026,
            'bulan' => [9],
        ]);
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'laporan-reseller').'.xlsx';
        file_put_contents($tmp, $response->getContent());

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            $rows = array_slice($spreadsheet->getSheetByName('Ringkasan Tagihan')->toArray(), 3);

            $pelangganDiTimeline = array_column($rows, 3);

            $this->assertCount(9, $pelangganDiTimeline);
            $this->assertContains('Milik Reseller A', $pelangganDiTimeline);
            $this->assertNotContains('Milik Reseller B', $pelangganDiTimeline);
        } finally {
            @unlink($tmp);
        }
    }
}