<?php

namespace Tests\Feature\Api\Pelanggan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
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
            "api.xendit.co/*" => Http::response([
                "id" => "xendit-inv-baru-1",
                "external_id" => "TGH-INV000001-1",
                "invoice_url" => "https://checkout.xendit.co/invoice/baru",
                "amount" => 150000,
                "status" => "PENDING",
                "expiry_date" => now()->addDays(3)->toIso8601String(),
            ], 200),
        ]);
    }

    private function buatTagihan(): array
    {
        $pelanggan = Pelanggan::factory()->sudahAktif()->create();

        $layanan = LayananInternet::factory()->create([
            "pelanggan_id" => $pelanggan->id,
        ]);

        $tagihan = Tagihan::factory()->create([
            "layanan_internet_id" => $layanan->id,
            "nomor_tagihan" => "INV000001",
            "total_tagihan" => 150000,
            "status_pembayaran" => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        Sanctum::actingAs($pelanggan);

        return [$pelanggan, $layanan, $tagihan];
    }

    public function test_pelanggan_bisa_regenerate_invoice_untuk_tagihan_belum_bayar(): void
    {
        [$pelanggan, , $tagihan] = $this->buatTagihan();

        $response = $this->postJson(
            "/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice"
        );

        $response->assertOk()
            ->assertJsonPath("data.pelanggan_id", $pelanggan->id)
            ->assertJsonPath("data.provider", "xendit")
            ->assertJsonPath("data.provider_reference", "xendit-inv-baru-1")
            ->assertJsonPath("data.provider_external_id", "TGH-INV000001-1")
            ->assertJsonPath("data.payment_url", "https://checkout.xendit.co/invoice/baru")
            ->assertJsonPath("data.status", StatusTransaksiEnum::PENDING->value);

        $this->assertDatabaseHas("pembayaran", [
            "pelanggan_id" => $pelanggan->id,
            "tagihan_id" => null,
            "provider" => "xendit",
            "provider_reference" => "xendit-inv-baru-1",
            "provider_external_id" => "TGH-INV000001-1",
            "payment_url" => "https://checkout.xendit.co/invoice/baru",
            "status" => StatusTransaksiEnum::PENDING->value,
            "jumlah_dibayar" => 150000,
        ]);

        $this->assertDatabaseHas("tagihan", [
            "id" => $tagihan->id,
            "status_pembayaran" => StatusPembayaranEnum::BELUM_BAYAR->value,
        ]);

        $this->assertDatabaseCount("pembayaran_tagihan", 0);
    }

    public function test_pelanggan_bisa_regenerate_invoice_untuk_sisa_tagihan_setelah_pembayaran_parsial(): void
    {
        [$pelanggan, , $tagihan] = $this->buatTagihan();

        $pembayaranLama = Pembayaran::create([
            "pelanggan_id" => $pelanggan->id,
            "tagihan_id" => null,
            "metode_pembayaran" => "tunai",
            "jumlah_dibayar" => 50000,
            "status" => StatusTransaksiEnum::BERHASIL,
            "dibayar_pada" => now(),
        ]);

        PembayaranTagihan::create([
            "pembayaran_id" => $pembayaranLama->id,
            "tagihan_id" => $tagihan->id,
            "jumlah_dialokasikan" => 50000,
        ]);

        $response = $this->postJson(
            "/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice"
        );

        $response->assertOk()
            ->assertJsonPath("data.jumlah_dibayar", "100000.00")
            ->assertJsonPath("data.status", StatusTransaksiEnum::PENDING->value);

        $this->assertDatabaseHas("pembayaran", [
            "pelanggan_id" => $pelanggan->id,
            "tagihan_id" => null,
            "jumlah_dibayar" => 100000,
            "status" => StatusTransaksiEnum::PENDING->value,
            "provider" => "xendit",
        ]);
    }

    public function test_tidak_bisa_regenerate_tagihan_yang_sudah_lunas(): void
    {
        [$pelanggan, , $tagihan] = $this->buatTagihan();

        $pembayaran = Pembayaran::create([
            "pelanggan_id" => $pelanggan->id,
            "tagihan_id" => null,
            "metode_pembayaran" => "tunai",
            "jumlah_dibayar" => 150000,
            "status" => StatusTransaksiEnum::BERHASIL,
            "dibayar_pada" => now(),
        ]);

        PembayaranTagihan::create([
            "pembayaran_id" => $pembayaran->id,
            "tagihan_id" => $tagihan->id,
            "jumlah_dialokasikan" => 150000,
        ]);

        $tagihan->update([
            "status_pembayaran" => StatusPembayaranEnum::SUDAH_BAYAR,
        ]);

        $this->postJson(
            "/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice"
        )->assertStatus(422);
    }

    public function test_pelanggan_lain_tidak_bisa_regenerate_tagihan_milik_orang_lain(): void
    {
        [$pemilik, , $tagihan] = $this->buatTagihan();

        $pelangganLain = Pelanggan::factory()->sudahAktif()->create();

        Sanctum::actingAs($pelangganLain);

        $this->postJson(
            "/api/pelanggan/tagihan/{$tagihan->id}/regenerate-invoice"
        )->assertStatus(403);
    }
}
