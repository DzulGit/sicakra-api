<?php

namespace Tests\Feature\Api\Webhook;

use App\Enums\StatusPembayaranEnum;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class XenditWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tagihan $tagihan;

    private string $validToken = 'test-webhook-token';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.xendit.webhook_verification_token', $this->validToken);
        $this->tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    public function test_webhook_tanpa_token_harus_401(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'status' => 'PAID',
        ]);

        $response->assertStatus(401);
        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_dengan_token_salah_harus_401(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'status' => 'PAID',
        ], ['X-Callback-Token' => 'wrong-token']);

        $response->assertStatus(401);
        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_status_paid_update_tagihan_dan_buat_pembayaran(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
            'amount' => 150000,
            'paid_amount' => 150000,
            'payment_method' => 'BCA',
        ], ['X-Callback-Token' => $this->getVerificationToken()]);

        $response->assertOk();

        $this->tagihan->refresh();
        $this->assertEquals(StatusPembayaranEnum::SUDAH_BAYAR, $this->tagihan->status_pembayaran);
        $this->assertNotNull($this->tagihan->dibayar_pada);

        $this->assertEquals(1, Pembayaran::count());
        $pembayaran = Pembayaran::first();
        $this->assertEquals('berhasil', $pembayaran->status->value);
        $this->assertEquals(150000, (float) $pembayaran->jumlah_dibayar);
        $this->assertEquals('BCA', $pembayaran->metode_pembayaran);
        $this->assertEquals('xendit-inv-123', $pembayaran->referensi_xendit);
    }

    public function test_webhook_status_paid_idempotent_tidak_dobel_update(): void
    {
        $this->tagihan->update([
            'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
            'dibayar_pada' => now(),
        ]);

        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
            'amount' => 150000,
            'paid_amount' => 150000,
            'payment_method' => 'BCA',
        ], ['X-Callback-Token' => $this->getVerificationToken()]);

        $response->assertOk();
        $this->assertEquals(1, Pembayaran::count());
    }

    public function test_webhook_status_expired_update_tagihan(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'id' => 'xendit-inv-123',
            'status' => 'EXPIRED',
            'amount' => 150000,
        ], ['X-Callback-Token' => $this->getVerificationToken()]);

        $response->assertOk();

        $this->tagihan->refresh();
        $this->assertEquals(StatusPembayaranEnum::KEDALUWARSA, $this->tagihan->status_pembayaran);
        $this->assertEquals('expired', $this->tagihan->xendit_invoice_status);

        $this->assertEquals(1, Pembayaran::count());
        $pembayaran = Pembayaran::first();
        $this->assertEquals('gagal', $pembayaran->status->value);
    }

    public function test_webhook_dengan_external_id_tidak_valid_harus_400(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'INVALID-FORMAT',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
        ], ['X-Callback-Token' => $this->getVerificationToken()]);

        $response->assertStatus(400);
        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_dengan_nomor_tagihan_tidak_dikenal_harus_404(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV9999999',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
        ], ['X-Callback-Token' => $this->getVerificationToken()]);

        $response->assertStatus(404);
        $this->assertEquals(0, Pembayaran::count());
    }

    private function getVerificationToken(): string
    {
        return $this->validToken;
    }
}
