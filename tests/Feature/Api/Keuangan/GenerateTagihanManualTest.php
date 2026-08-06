<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateTagihanManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Faktikan event supaya listener queued (BuatInvoiceXendit) tidak
        // benar-benar memanggil API Xendit selama test.
        Event::fake();
    }

    public function test_keuangan_generate_tagihan_untuk_layanan_aktif(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}");

        $response->assertCreated();
        $this->assertCount(1, $response->json('data'));

        $tagihan = Tagihan::where('layanan_internet_id', $layanan->id)->first();
        $this->assertNotNull($tagihan);
        $this->assertEquals(now()->month, $tagihan->periode_bulan);
        $this->assertEquals(now()->year, $tagihan->periode_tahun);
        $this->assertEquals($layanan->paketInternet->harga, $tagihan->harga_snapshot);
    }

    public function test_keuangan_generate_ulang_periode_sama_harus_422(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}")->assertCreated();

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}");
        $response->assertStatus(422);
        $this->assertEquals(1, Tagihan::count());
    }

    public function test_generate_untuk_pelanggan_tanpa_layanan_aktif_harus_422(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}")
            ->assertStatus(422);
    }

    public function test_operasional_tidak_bisa_generate_tagihan_harus_403(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $pelanggan = Pelanggan::factory()->create();

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}")
            ->assertStatus(403);
        $this->assertEquals(0, Tagihan::count());
    }

    public function test_keuangan_generate_dengan_jumlah_bulan_banyak(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", [
            'jumlah_bulan' => 5,
        ]);

        $response->assertCreated()->assertJsonCount(1, 'data');

        $tagihan = Tagihan::where('layanan_internet_id', $layanan->id)->first();
        $this->assertEquals(5, $tagihan->jumlah_bulan);
        $this->assertEquals($layanan->paketInternet->harga * 5, $tagihan->total_tagihan);
    }

    public function test_keuangan_regenerate_ubah_jumlah_bulan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'harga_snapshot' => 100000,
            'total_tagihan' => 100000,
            'jumlah_bulan' => 1,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/keuangan/tagihan/{$tagihan->id}/regenerate", [
            'jumlah_bulan' => 12,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.jumlah_bulan', 12)
            ->assertJsonPath('data.total_tagihan', '1200000.00');

        $this->assertEquals(12, $tagihan->fresh()->jumlah_bulan);
    }

    public function test_regenerate_tidak_bisa_untuk_tagihan_sudah_bayar(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'total_tagihan' => 100000,
            'jumlah_bulan' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan->id}/regenerate", [
            'jumlah_bulan' => 6,
        ])->assertForbidden();

        $this->assertEquals(1, $tagihan->fresh()->jumlah_bulan);
    }

    public function test_regenerate_validasi_jumlah_bulan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'total_tagihan' => 100000,
            'jumlah_bulan' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan->id}/regenerate", [
            'jumlah_bulan' => 13,
        ])->assertStatus(422);
    }
}
