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

        $tagihan1 = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 1,
            'periode_tahun' => 2026,
            'harga_snapshot' => 150000,
            'total_tagihan' => 150000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $tagihan2 = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 2,
            'periode_tahun' => 2026,
            'harga_snapshot' => 150000,
            'total_tagihan' => 150000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        return [$pelanggan, $tagihan1, $tagihan2];
    }

    public function test_bayar_tunai_dengan_jumlah_bulan_melunasi_tagihan_target(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_bulan' => 2,
        ])->assertOk();

        $this->assertSame(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan1->fresh()->status_pembayaran
        );

        $this->assertSame(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan2->fresh()->status_pembayaran
        );

        $sisaT2 = (float) PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_tagihan.pembayaran_id')
            ->where('pembayaran_tagihan.tagihan_id', $tagihan2->id)
            ->sum('jumlah_dialokasikan');

        $this->assertSame(0.0, $sisaT2);

        $kelebihan = (float) PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_tagihan.pembayaran_id')
            ->where('pembayaran_tagihan.tagihan_id', $tagihan1->id)
            ->sum('jumlah_dialokasikan');

        $this->assertSame(150000.0, $kelebihan);

        $this->assertDatabaseHas('mutasi_saldo_kredit', [
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 150000.0,
        ]);
    }

    public function test_bayar_tunai_dengan_jumlah_dibayar_tetap_berlaku(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_dibayar' => 150000,
        ])->assertOk();

        $this->assertSame(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan1->fresh()->status_pembayaran
        );

        $this->assertSame(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan2->fresh()->status_pembayaran
        );
    }

    public function test_bayar_tunai_tanpa_nominal_ditolak(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [])
            ->assertUnprocessable();
    }

    public function test_bayar_tunai_tagihan_sudah_lunas_ditolak(): void
    {
        [$pelanggan, $tagihan1, $tagihan2] = $this->buatAdminDanPelanggan();

        $tagihan1->update(['status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR]);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan1->id}/bayar-tunai", [
            'jumlah_bulan' => 1,
        ])->assertStatus(403);
    }
}