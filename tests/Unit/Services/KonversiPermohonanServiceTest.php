<?php

namespace Tests\Unit\Services;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Models\LayananInternet;
use App\Models\PermohonanLayanan;
use App\Models\Tagihan;
use App\Services\KonversiPermohonanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class KonversiPermohonanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Faktikan event supaya listener queued (BuatInvoiceXendit) tidak
        // benar-benar memanggil API Xendit selama test.
        Event::fake();
    }

    public function test_konversi_pemasangan_baru_membuat_layanan_internet_baru(): void
    {
        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
        ]);

        $layanan = app(KonversiPermohonanService::class)->konversi($permohonan);

        $this->assertInstanceOf(LayananInternet::class, $layanan);
        $this->assertEquals(StatusLayananEnum::AKTIF, $layanan->status);
        $this->assertEquals($permohonan->id, $layanan->permohonan_layanan_id);
        $this->assertStringStartsWith('LYN', $layanan->nomor_layanan);

        $permohonan->refresh();
        $this->assertEquals(StatusPermohonanEnum::DIKONVERSI, $permohonan->status);
    }

    public function test_konversi_pemasangan_baru_generate_nomor_pelanggan_jika_layanan_pertama(): void
    {
        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
        ]);
        $permohonan->pelanggan()->update(['nomor_pelanggan' => null]);

        app(KonversiPermohonanService::class)->konversi($permohonan);

        $permohonan->pelanggan->refresh();
        $this->assertNotNull($permohonan->pelanggan->nomor_pelanggan);
        $this->assertStringStartsWith('PLG', $permohonan->pelanggan->nomor_pelanggan);
    }

    public function test_konversi_pemasangan_baru_terapkan_promo_gratis_dari_paket(): void
    {
        $paket = \App\Models\PaketInternet::factory()->create(['promo_gratis_bulan' => 1]);

        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => 'reguler',
        ]);

        $layanan = app(KonversiPermohonanService::class)->konversi($permohonan);

        $this->assertEquals(1, $layanan->bebas_tagihan_bulan);
        // Jadwal tagihan pertama dimundurkan sesuai promo: aktivasi + (1 + promo) bulan.
        $this->assertEquals(
            now()->startOfDay()->addMonthsNoOverflow(2)->toDateString(),
            $layanan->tanggal_mulai_penagihan->toDateString(),
        );
    }

    public function test_konversi_pemasangan_baru_paket_tanpa_promo_tetap_ditagih_bulan_depan(): void
    {
        $paket = \App\Models\PaketInternet::factory()->create(['promo_gratis_bulan' => 0]);

        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => 'reguler',
        ]);

        $layanan = app(KonversiPermohonanService::class)->konversi($permohonan);

        $this->assertEquals(0, $layanan->bebas_tagihan_bulan);
        $this->assertEquals(
            now()->startOfDay()->addMonthsNoOverflow(1)->toDateString(),
            $layanan->tanggal_mulai_penagihan->toDateString(),
        );
    }

    public function test_konversi_pemasangan_baru_langsung_generate_tagihan_bulan_ini(): void
    {
        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
        ]);

        $layanan = app(KonversiPermohonanService::class)->konversi($permohonan);

        $this->assertEquals(1, Tagihan::where('layanan_internet_id', $layanan->id)->count());

        $tagihan = Tagihan::where('layanan_internet_id', $layanan->id)->first();
        $this->assertEquals(now()->month, $tagihan->periode_bulan);
        $this->assertEquals(now()->year, $tagihan->periode_tahun);
        $this->assertEquals($layanan->paketInternet->harga, $tagihan->harga_snapshot);
        $this->assertEquals($layanan->paketInternet->harga, $tagihan->total_tagihan);
    }

    public function test_konversi_pemasangan_baru_tidak_generate_ulang_nomor_pelanggan_jika_sudah_ada(): void
    {
        $permohonan = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
        ]);
        $permohonan->pelanggan()->update(['nomor_pelanggan' => 'PLG000777']);

        app(KonversiPermohonanService::class)->konversi($permohonan);

        $permohonan->pelanggan->refresh();
        $this->assertEquals('PLG000777', $permohonan->pelanggan->nomor_pelanggan);
    }

    public function test_konversi_relokasi_update_layanan_lama_bukan_membuat_baru(): void
    {
        $layananLama = LayananInternet::factory()->create([
            'alamat_pemasangan' => 'Alamat Lama No. 1',
        ]);

        $permohonanRelokasi = PermohonanLayanan::factory()->create([
            'jenis_permohonan' => JenisPermohonanEnum::RELOKASI,
            'layanan_internet_id' => $layananLama->id,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'alamat_pemasangan' => 'Alamat Baru No. 99',
        ]);

        $jumlahLayananSebelum = LayananInternet::count();

        $hasil = app(KonversiPermohonanService::class)->konversi($permohonanRelokasi);

        // Tidak boleh ada baris layanan_internet baru
        $this->assertEquals($jumlahLayananSebelum, LayananInternet::count());
        $this->assertEquals($layananLama->id, $hasil->id);
        $this->assertEquals('Alamat Baru No. 99', $hasil->alamat_pemasangan);

        $this->assertDatabaseHas('riwayat_relokasi', [
            'layanan_internet_id' => $layananLama->id,
            'alamat_lama' => 'Alamat Lama No. 1',
            'alamat_baru' => 'Alamat Baru No. 99',
        ]);
    }
}