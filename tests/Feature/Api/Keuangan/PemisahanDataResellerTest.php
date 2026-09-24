<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PemisahanDataResellerTest extends TestCase
{
    use RefreshDatabase;

    private function buatResellerDenganPelangganAktif(): array
    {
        $reseller = Admin::factory()->reseller()->create();

        $pelanggan = Pelanggan::factory()->create([
            'nama_lengkap' => 'Citra Milik Reseller',
            'nomor_pelanggan' => 'PLG-9876',
            'reseller_id' => $reseller->id,
        ]);

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $periode = now('Asia/Jakarta');
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $periode->month,
            'periode_tahun' => $periode->year,
            'harga_snapshot' => 250000,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        return [$reseller, $pelanggan, $tagihan];
    }

    public function test_pendaftar_baru_keuangan_tidak_menampilkan_pelanggan_reseller(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->buatResellerDenganPelangganAktif();

        $json = $this->getJson('/api/admin/keuangan/pendaftar-baru')
            ->assertOk();

        $ids = collect($json->json('data'))->pluck('id');

        $this->assertCount(0, $ids);
    }

    public function test_detail_tagihan_draft_reseller_tidak_bisa_diakses_admin_keuangan(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelangganAktif();
        unset($reseller, $pelanggan);

        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/keuangan/tagihan/{$tagihan->id}")
            ->assertNotFound();
    }

    public function test_terbitkan_tagihan_draft_reseller_ditolak(): void
    {
        [$reseller, $pelanggan, $tagihan] = $this->buatResellerDenganPelangganAktif();
        unset($reseller, $pelanggan);

        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/keuangan/tagihan/terbitkan', [
            'tagihan_ids' => [$tagihan->id],
        ])
            ->assertOk()
            ->assertJsonPath('gagal', 1);

        $this->assertSame(
            StatusPembayaranEnum::BELUM_DITERBITKAN,
            $tagihan->fresh()->status_pembayaran
        );
    }
}