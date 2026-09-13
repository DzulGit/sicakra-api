<?php

namespace Tests\Feature\Api\Webhook;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class XenditWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tagihan $tagihan;

    private Pelanggan $pelanggan;

    private string $validToken = 'test-webhook-token';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.xendit.webhook_verification_token', $this->validToken);

        $this->tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'total_tagihan' => 150000,
        ]);

        $this->pelanggan = $this->tagihan->layananInternet->pelanggan;
    }

    public function test_webhook_tanpa_token_harus_401(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-999',
            'status' => 'PAID',
        ]);

        $response->assertStatus(401);
        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_dengan_token_salah_harus_401(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-999',
            'status' => 'PAID',
        ], [
            'X-Callback-Token' => 'wrong-token',
        ]);

        $response->assertStatus(401);
        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_status_paid_menyelesaikan_pembayaran_dan_alokasi_tagihan(): void
    {
        $pembayaran = $this->buatPembayaranPending();

        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-' . $pembayaran->id,
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
            'amount' => 150000,
            'paid_amount' => 150000,
            'payment_method' => 'BCA',
        ], [
            'X-Callback-Token' => $this->getVerificationToken(),
        ]);

        $response->assertOk();

        $pembayaran->refresh();
        $this->tagihan->refresh();

        $this->assertNull($pembayaran->tagihan_id);

        $this->assertEquals(
            $this->pelanggan->id,
            $pembayaran->pelanggan_id
        );

        $this->assertEquals(
            StatusTransaksiEnum::BERHASIL,
            $pembayaran->status
        );

        $this->assertEquals(
            150000,
            (float) $pembayaran->jumlah_dibayar
        );

        $this->assertEquals(
            'BCA',
            $pembayaran->metode_pembayaran
        );

        $this->assertEquals(
            'xendit-inv-123',
            $pembayaran->provider_reference
        );

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $this->tagihan->status_pembayaran
        );

        $this->assertNotNull($this->tagihan->dibayar_pada);

        $this->assertEquals(
            1,
            PembayaranTagihan::where('pembayaran_id', $pembayaran->id)->count()
        );

        $alokasi = PembayaranTagihan::where('pembayaran_id', $pembayaran->id)
            ->first();

        $this->assertEquals(
            $this->tagihan->id,
            $alokasi->tagihan_id
        );

        $this->assertEquals(
            150000,
            (float) $alokasi->jumlah_dialokasikan
        );
    }

    public function test_webhook_status_paid_idempotent_tidak_dobel_update(): void
    {
        $pembayaran = $this->buatPembayaranPending();

        $payload = [
            'external_id' => 'PAY-' . $pembayaran->id,
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
            'amount' => 150000,
            'paid_amount' => 150000,
            'payment_method' => 'BCA',
        ];

        $responsePertama = $this->postJson(
            '/api/webhook/xendit',
            $payload,
            ['X-Callback-Token' => $this->getVerificationToken()]
        );

        $responsePertama->assertOk();

        $responseKedua = $this->postJson(
            '/api/webhook/xendit',
            $payload,
            ['X-Callback-Token' => $this->getVerificationToken()]
        );

        $responseKedua->assertOk();

        $this->assertEquals(1, Pembayaran::count());

        $this->assertEquals(
            1,
            PembayaranTagihan::where('pembayaran_id', $pembayaran->id)->count()
        );

        $this->tagihan->refresh();

        $this->assertEquals(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $this->tagihan->status_pembayaran
        );
    }

    public function test_webhook_status_expired_menggagalkan_pembayaran(): void
    {
        $pembayaran = $this->buatPembayaranPending();

        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-' . $pembayaran->id,
            'id' => 'xendit-inv-123',
            'status' => 'EXPIRED',
            'amount' => 150000,
        ], [
            'X-Callback-Token' => $this->getVerificationToken(),
        ]);

        $response->assertOk();

        $pembayaran->refresh();
        $this->tagihan->refresh();

        $this->assertEquals(
            StatusTransaksiEnum::GAGAL,
            $pembayaran->status
        );

        $this->assertEquals(
            'expired',
            $pembayaran->provider_status
        );

        $this->assertEquals(
            StatusPembayaranEnum::BELUM_BAYAR,
            $this->tagihan->status_pembayaran
        );

        $this->assertEquals(
            0,
            PembayaranTagihan::count()
        );
    }

    public function test_webhook_dengan_external_id_tidak_valid_harus_400(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'INVALID-FORMAT',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
        ], [
            'X-Callback-Token' => $this->getVerificationToken(),
        ]);

        $response->assertStatus(400);

        $this->assertEquals(0, Pembayaran::count());
    }

    public function test_webhook_dengan_pembayaran_tidak_dikenal_harus_404(): void
    {
        $response = $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-999999',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
        ], [
            'X-Callback-Token' => $this->getVerificationToken(),
        ]);

        $response->assertStatus(404);

        $this->assertEquals(0, Pembayaran::count());
    }

    private function buatPembayaranPending(): Pembayaran
    {
        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $this->pelanggan->id,
            'metode_pembayaran' => 'BCA',
            'provider' => 'xendit',
            'jumlah_dibayar' => 150000,
            'status' => StatusTransaksiEnum::PENDING,
        ]);

        $pembayaran->update([
            'provider_external_id' => 'PAY-' . $pembayaran->id,
        ]);

        return $pembayaran->fresh();
    }

    private function getVerificationToken(): string
    {
        return $this->validToken;
    }
}