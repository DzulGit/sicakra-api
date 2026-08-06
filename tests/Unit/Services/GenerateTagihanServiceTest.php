<?php

namespace Tests\Unit\Services;

use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Models\Tagihan;
use App\Services\GenerateTagihanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GenerateTagihanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_generate_tagihan_berhasil_untuk_layanan_aktif(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-15',
        ]);

        $tagihan = app(GenerateTagihanService::class)
            ->generateUntukLayanan($layanan, 7, 2026);

        $this->assertNotNull($tagihan);
        $this->assertEquals('2026-07-15', $tagihan->tanggal_jatuh_tempo->toDateString());
    }

    public function test_tidak_generate_tagihan_untuk_layanan_nonaktif(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::NONAKTIF,
        ]);

        $tagihan = app(GenerateTagihanService::class)
            ->generateUntukLayanan($layanan, 7, 2026);

        $this->assertNull($tagihan);
    }

    public function test_tidak_generate_tagihan_dobel_di_periode_yang_sama(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-15',
        ]);

        $service = app(GenerateTagihanService::class);
        $service->generateUntukLayanan($layanan, 7, 2026);
        $tagihanKedua = $service->generateUntukLayanan($layanan, 7, 2026);

        $this->assertNull($tagihanKedua);
        $this->assertEquals(1, Tagihan::where('layanan_internet_id', $layanan->id)->count());
    }

    public function test_tidak_generate_tagihan_dobel_untuk_periode_di_dalam_tagihan_multi_bulan(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-15',
        ]);

        $service = app(GenerateTagihanService::class);
        $service->generateUntukLayanan($layanan, 1, 2026, 3); // Jan+Feb+Mar

        // Generate tagihan Feb harus ditolak karena sudah ter-cover tagihan multi-bulan.
        $tagihanFebruari = $service->generateUntukLayanan($layanan, 2, 2026);
        $tagihanMaret = $service->generateUntukLayanan($layanan, 3, 2026);

        $this->assertNull($tagihanFebruari);
        $this->assertNull($tagihanMaret);
        $this->assertEquals(1, Tagihan::where('layanan_internet_id', $layanan->id)->count());
    }

    public function test_tanggal_jatuh_tempo_di_clamp_untuk_bulan_yang_lebih_pendek(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-31', // aktif di tanggal 31
        ]);

        // Februari 2026 cuma 28 hari (bukan tahun kabisat)
        $tagihan = app(GenerateTagihanService::class)
            ->generateUntukLayanan($layanan, 2, 2026);

        $this->assertEquals('2026-02-28', $tagihan->tanggal_jatuh_tempo->toDateString());
    }

    public function test_snapshot_paket_tersimpan_benar(): void
    {
        $layanan = LayananInternet::factory()->create([
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2026-01-10',
        ]);
        $layanan->load('paketInternet');

        $tagihan = app(GenerateTagihanService::class)
            ->generateUntukLayanan($layanan, 3, 2026);

        $this->assertEquals($layanan->paketInternet->nama_paket, $tagihan->nama_paket_snapshot);
        $this->assertEquals($layanan->paketInternet->harga, $tagihan->harga_snapshot);
    }
}
