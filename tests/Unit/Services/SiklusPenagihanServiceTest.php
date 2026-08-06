<?php

namespace Tests\Unit\Services;

use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Services\SiklusPenagihanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiklusPenagihanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Aman: test berjalan di sqlite in-memory, JANGAN menyentuh DB Supabase (pgsql).
        // Dipasang sebelum app boot (parent::setUp) karena Dotenv Laravel immutable
        // tidak akan menimpa env yang sudah di-set via putenv.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        parent::setUp();

        // Bekukan "hari ini" supaya skenario tanggal deterministik.
        Carbon::setTestNow('2026-01-15');
    }

    public function test_bayar_tagihan_3_bulan_memajukan_jadwal_ke_periode_pertama_yang_belum_terbayar(): void
    {
        $pelanggan = Pelanggan::factory()->create(['tanggal_tagihan' => 15]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2025-12-15',
            // Pasca cron: tagihan Jan sudah dibuat, jadwal sudah dimajukan +1 bulan.
            'tanggal_mulai_penagihan' => '2026-02-15',
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 1,
            'periode_tahun' => 2026,
            'jumlah_bulan' => 3, // menutupi Jan, Feb, Mar
        ]);

        app(SiklusPenagihanService::class)->majukanJadwalSetelahPembayaran($tagihan);

        $layanan->refresh();
        // Siklus asli (hari 15) utuh. Jadwal berikutnya = Apr (Jan + 3 bulan), BUKAN May:
        // memajukan jadwal saat ini (Feb) dengan 3 bulan akan melewati Apr dan
        // memberi pelanggan 1 bulan gratis.
        $this->assertEquals('2026-04-15', $layanan->tanggal_mulai_penagihan->toDateString());
    }

    public function test_snap_to_end_of_month_berlaku_pada_jadwal_setelah_pembayaran(): void
    {
        $pelanggan = Pelanggan::factory()->create(['tanggal_tagihan' => 31]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => '2025-12-31',
            'tanggal_mulai_penagihan' => '2026-02-28', // pasca cron, base 31 di-Feb di-snap ke 28
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 1,
            'periode_tahun' => 2026,
            'jumlah_bulan' => 2, // menutupi Jan & Feb -> jadwal berikutnya Mar
        ]);

        app(SiklusPenagihanService::class)->majukanJadwalSetelahPembayaran($tagihan);

        $layanan->refresh();
        // Feb di-snap ke 28; Mar (31 hari) kembali ke 31.
        $this->assertEquals('2026-03-31', $layanan->tanggal_mulai_penagihan->toDateString());
    }
}
