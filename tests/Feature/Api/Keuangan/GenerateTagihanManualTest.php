<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
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
}
