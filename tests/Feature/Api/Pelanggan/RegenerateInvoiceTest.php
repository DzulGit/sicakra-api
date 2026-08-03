<?php

namespace Tests\Feature\Api\Pelanggan;

use App\Enums\StatusPembayaranEnum;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegenerateInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'id' => 'xendit-inv-baru-1',
                'external_id' => 'TGH-INV000001-1',
                'invoice_url' => 'https://checkout.xendit.co/invoice/baru',
                'amount' => 150000,
                'status' => 'PENDING',
                'expiry_date' => now()->addDays(3)->toIso8601String(),
            ], 200),
        ]);
    }

    private function buatTagihanKedaluwarsa(bool $sudahBayar = false): int
    {
        $layanan = LayananInternet::factory()->create();
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'nomor_tagihan' => 'INV000001',
            'status_pembayaran' => $sudahBayar ? StatusPembayaranEnum::SUDAH_BAYAR : StatusPembayaranEnum::KEDALUWARSA,
            'xendit_invoice_retry_count' => 1,
        ]);

        Sanctum::actingAs($layanan->pelanggan);

        return $tagihan->id;
    }

    public function test_pelanggan_bisa_regenerate_invoice_yang_kadaluwarsa(): void
    {
        $id = $this->buatTagihanKedaluwarsa();
        $sebelum = Tagihan::find($id);

        $response = $this->postJson("/api/pelanggan/tagihan/{$id}/regenerate-invoice");

        $response->assertOk();
        $this->assertEquals('https://checkout.xendit.co/invoice/baru', $response->json('data.xendit_invoice_url'));

        $tagihan = $sebelum->fresh();
        $this->assertEquals('xendit-inv-baru-1', $tagihan->xendit_invoice_id);
        $this->assertEquals('TGH-INV000001-1', $tagihan->xendit_external_id);
        $this->assertEquals(StatusPembayaranEnum::BELUM_BAYAR, $tagihan->status_pembayaran);
        $this->assertEquals(2, $tagihan->xendit_invoice_retry_count);
    }

    public function test_tidak_bisa_regenerate_tagihan_yang_sudah_dibayar(): void
    {
        $id = $this->buatTagihanKedaluwarsa(sudahBayar: true);

        $this->postJson("/api/pelanggan/tagihan/{$id}/regenerate-invoice")
            ->assertStatus(422);
    }

    public function test_tidak_bisa_regenerate_setelah_maksimal_retry(): void
    {
        $layanan = LayananInternet::factory()->create();
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'nomor_tagihan' => 'INV000001',
            'status_pembayaran' => StatusPembayaranEnum::KEDALUWARSA,
            'xendit_invoice_retry_count' => 3,
        ]);
        Sanctum::actingAs($layanan->pelanggan);

        $this->postJson("/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice")
            ->assertStatus(422);
    }

    public function test_pelanggan_lain_tidak_bisa_regenerate_tagihan_milik_orang_lain(): void
    {
        $layanan = LayananInternet::factory()->create();
        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::KEDALUWARSA,
        ]);
        $pelangganLain = Pelanggan::factory()->create();
        Sanctum::actingAs($pelangganLain);

        $this->postJson("/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice")
            ->assertStatus(403);
    }
}