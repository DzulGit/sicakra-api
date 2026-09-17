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

class TerbitkanTagihanDraftIndexTest extends TestCase
{
    use RefreshDatabase;

    private function buatDraft(string $nama, string $nik, string $nomorPelanggan): Tagihan
    {
        $pelanggan = Pelanggan::factory()->create([
            'nama_lengkap' => $nama,
            'nik' => $nik,
            'nomor_pelanggan' => $nomorPelanggan,
        ]);

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        // "Permah punya tagihan" — prasyarat draft nomor 2 boleh di-list.
        Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 1,
            'periode_tahun' => 2020,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
        ]);

        $t = now('Asia/Jakarta');

        return Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $t->month,
            'periode_tahun' => $t->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);
    }

    public function test_search_menyaring_berdasarkan_nama(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $ini = $this->buatDraft('Budi Santoso', '3201010102', 'PLG-0001');
        $bukan = $this->buatDraft('Citra Lestari', '3201010103', 'PLG-0002');

        $this->getJson('/api/admin/keuangan/tagihan/draft?search=Budi')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ini->id);

        $this->getJson('/api/admin/keuangan/tagihan/draft?search=budi%20santoso')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ini->id);

        $this->getJson('/api/admin/keuangan/tagihan/draft?search=' . urlencode($bukan->layananInternet->pelanggan->nik))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $bukan->id);

        $this->getJson('/api/admin/keuangan/tagihan/draft?search=PLG-0002')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $bukan->id);
    }

    public function test_per_page_all_menampilkan_semua_draft(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->buatDraft('Budi Santoso', '3201010102', 'PLG-0001');
        $this->buatDraft('Citra Lestari', '3201010103', 'PLG-0002');

        $json = $this->getJson('/api/admin/keuangan/tagihan/draft?per_page=all')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->assertSame(2, $json->json('data.total'));
        $this->assertSame(1, $json->json('data.last_page'));
        $this->assertSame(100000, $json->json('data.per_page'));
    }
}