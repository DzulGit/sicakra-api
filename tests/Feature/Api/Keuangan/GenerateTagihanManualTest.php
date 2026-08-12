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
use Illuminate\Support\Facades\Http;
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

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", [
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
        ]);

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

        $params = [
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
        ];

        $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", $params)->assertCreated();

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", $params);
        $response->assertStatus(422);
        $this->assertStringContainsString('sudah diterbitkan', $response->json('message'));
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

    public function test_generate_periode_tercover_tagihan_sudah_bayar_harus_422(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", [
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('sudah diterbitkan', $response->json('message'));
        $this->assertEquals(1, Tagihan::count());
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

    public function test_perbarui_link_tagihan_belum_bayar_mengembalikan_url_baru(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::KEDALUWARSA,
            'xendit_invoice_retry_count' => 2,
        ]);

        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv-baru',
                'external_id' => 'TGH-'.$tagihan->nomor_tagihan.'-3',
                'invoice_url' => 'https://checkout.xendit.co/web/inv-baru',
                'expiry_date' => now()->addDays(7)->toIso8601String(),
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/keuangan/tagihan/{$tagihan->id}/perbarui-link");

        $response->assertOk()
            ->assertJsonPath('message', 'Link pembayaran berhasil diperbarui.')
            ->assertJsonPath('data.xendit_invoice_url', 'https://checkout.xendit.co/web/inv-baru')
            ->assertJsonPath('data.xendit_invoice_status', 'active');

        $reload = $tagihan->fresh();
        $this->assertEquals('inv-baru', $reload->xendit_invoice_id);
        $this->assertEquals(3, $reload->xendit_invoice_retry_count);
        $this->assertEquals(StatusPembayaranEnum::BELUM_BAYAR, $reload->status_pembayaran);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.xendit.co/v2/invoices')
                && $request['invoice_duration'] === 7 * 86400;
        });
    }

    public function test_perbarui_link_tagihan_sudah_bayar_harus_422(): void
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
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/{$tagihan->id}/perbarui-link")
            ->assertStatus(422);
    }
}
