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

class TagihanListDraftFilterTest extends TestCase
{
    use RefreshDatabase;

    private function buatTagihanTerbit(string $nama, string $nik, string $nomorPelanggan): Tagihan
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

        $t = now('Asia/Jakarta');

        return Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $t->month,
            'periode_tahun' => $t->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    public function test_search_menyaring_list_tagihan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $ini = $this->buatTagihanTerbit('Budi Santoso', '3201010102', 'PLG-0001');
        $bukan = $this->buatTagihanTerbit('Citra Lestari', '3201010103', 'PLG-0002');

        $this->getJson('/api/admin/keuangan/tagihan?search=Budi')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ini->id);

        $this->getJson('/api/admin/keuangan/tagihan?search=' . urlencode($bukan->layananInternet->pelanggan->nik))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $bukan->id);

        $this->getJson('/api/admin/keuangan/tagihan?search=PLG-0002')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $bukan->id);

        $this->getJson('/api/admin/keuangan/tagihan?search=sri%20wahyu')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $pelanggan = Pelanggan::factory()->create(['nama_lengkap' => 'Sri Wahyu']);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $t = now('Asia/Jakarta');
        $sri = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $t->month,
            'periode_tahun' => $t->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $this->getJson('/api/admin/keuangan/tagihan?search=sri%20wahyu')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $sri->id);
    }

    public function test_per_page_all_menampilkan_semua_tagihan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->buatTagihanTerbit('Budi Santoso', '3201010102', 'PLG-0001');
        $this->buatTagihanTerbit('Citra Lestari', '3201010103', 'PLG-0002');

        $json = $this->getJson('/api/admin/keuangan/tagihan?per_page=all')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->assertSame(2, $json->json('data.total'));
        $this->assertSame(1, $json->json('data.last_page'));
    }
}