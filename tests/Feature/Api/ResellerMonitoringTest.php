<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_operasional_bisa_melihat_statistik_semua_reseller(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => PaketInternet::factory()->create(['reseller_id' => $reseller->id])->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 100000,
        ]);

        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 100000,
            'dibayar_pada' => now(),
        ]);

        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihan->id,
            'jumlah_dialokasikan' => 100000,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/statistik')
            ->assertOk()
            ->assertJsonPath('data.stats.total_reseller', 1)
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.total_pendapatan', 100000)
            ->assertJsonPath('data.distribusi_pelanggan.0.label', $reseller->nama_lengkap)
            ->assertJsonCount(2, 'data.transaksi_terbaru')
            ->assertJsonFragment([
                'jenis' => 'tagihan',
                'nomor' => $tagihan->nomor_tagihan,
            ])
            ->assertJsonFragment([
                'jenis' => 'pembayaran',
                'nomor' => $tagihan->nomor_tagihan,
                'pelanggan' => $pelanggan->nama_lengkap,
                'nominal' => 100000,
                'status' => 'Lunas',
            ]);
    }

    public function test_statistik_per_reseller_mengembalikan_trend_status_dan_distribusi_paket(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id, 'nama_paket' => 'Wifi 20 Mbps']);
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/reseller/{$reseller->id}/statistik")
            ->assertOk()
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.pelanggan_aktif', 1)
            ->assertJsonPath('data.stats.total_paket', 1)
            ->assertJsonPath('data.distribusi_paket.0.label', 'Wifi 20 Mbps')
            ->assertJsonCount(12, 'data.trend_pendapatan');
    }

    public function test_daftar_transaksi_dapat_difilter_per_reseller_dan_periode(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();
        $resellerLain = Admin::factory()->reseller()->create();

        $bulanIni = now()->startOfMonth();
        $bulanLalu = now()->subMonthNoOverflow()->startOfMonth();

        // Transaksi milik reseller terpilih, periode bulan ini.
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => PaketInternet::factory()->create(['reseller_id' => $reseller->id])->id,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'total_tagihan' => 150000,
        ]);
        $tagihan->forceFill(['created_at' => $bulanIni->copy()->addDays(3)])->save();

        // Transaksi milik reseller LAIN, harus tersaring saat difilter.
        $pelangganLain = Pelanggan::factory()->create(['reseller_id' => $resellerLain->id]);
        $layananLain = LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganLain->id,
            'paket_internet_id' => PaketInternet::factory()->create(['reseller_id' => $resellerLain->id])->id,
        ]);
        $tagihanLain = Tagihan::factory()->create([
            'layanan_internet_id' => $layananLain->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'total_tagihan' => 250000,
        ]);
        $tagihanLain->forceFill(['created_at' => $bulanIni->copy()->addDays(5)])->save();

        // Transaksi reseller terpilih, tapi di luar periode (bulan lalu).
        $tagihanBulanLalu = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulanLalu->month,
            'periode_tahun' => $bulanLalu->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'total_tagihan' => 50000,
        ]);
        $tagihanBulanLalu->forceFill(['created_at' => $bulanLalu->copy()->addDays(2)])->save();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/reseller/transaksi?reseller_id={$reseller->id}&tahun={$bulanIni->year}&bulan={$bulanIni->month}");

        $res->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 20)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $tagihan->id)
            ->assertJsonPath('data.data.0.jenis', 'tagihan')
            ->assertJsonPath('data.data.0.nomor', $tagihan->nomor_tagihan)
            ->assertJsonPath('data.data.0.reseller', $reseller->nama_lengkap)
            ->assertJsonPath('data.data.0.nominal', 150000);
    }

    public function test_daftar_transaksi_menggabungkan_tagihan_dan_pembayaran_dengan_urut_waktu(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => PaketInternet::factory()->create(['reseller_id' => $reseller->id])->id,
        ]);

        // Pembayaran lebih lama, tagihan lebih baru -> tagihan harus di atas.
        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 90000,
            'dibayar_pada' => now()->subDays(2),
        ]);

        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 90000,
        ]);
        $tagihan->forceFill(['created_at' => now()->subDay()])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/transaksi')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.jenis', 'tagihan')
            ->assertJsonPath('data.data.1.jenis', 'pembayaran')
            ->assertJsonPath('data.data.1.nominal', 90000)
            ->assertJsonPath('data.data.1.status', 'Lunas');
    }

    public function test_daftar_transaksi_menolak_periode_tidak_valid(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/transaksi?bulan=13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bulan');
    }

    public function test_reseller_tidak_bisa_mengakses_daftar_transaksi(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/transaksi')
            ->assertForbidden();
    }

    public function test_reseller_tidak_bisa_mengakses_data_reseller_lain(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller/statistik')
            ->assertForbidden();
    }

    public function test_laporan_pdf_dan_excel_dapat_diunduh(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/reseller/laporan', [
                'reseller_id' => null,
                'tahun' => 2026,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/reseller/laporan/excel', [
                'reseller_id' => null,
                'tahun' => 2026,
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}