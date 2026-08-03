<?php

namespace Tests\Unit\Listeners;

use App\Events\TagihanDibuat;
use App\Listeners\BuatInvoiceXendit;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuatInvoiceXenditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.xendit.secret_key', 'test-secret-key');
        Config::set('services.xendit.invoice_duration', 259200);
    }

    public function test_listener_memanggil_xendit_api_dan_update_tagihan(): void
    {
        $tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'total_tagihan' => 150000,
            'jumlah_bulan' => 1,
        ]);

        Http::fake([
            'api.xendit.co/*' => Http::response([
                'id' => 'xendit-inv-abc-123',
                'invoice_url' => 'https://checkout.xendit.co/invoice/abc-123',
                'external_id' => 'TGH-INV000001',
                'amount' => 150000,
                'status' => 'PENDING',
                'expiry_date' => now()->addDays(3)->toIso8601String(),
            ], 200),
        ]);

        $listener = app(BuatInvoiceXendit::class);
        $listener->handle(new TagihanDibuat($tagihan));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.xendit.co/v2/invoices'
                && $request->method() === 'POST';
        });

        $tagihan->refresh();

        $this->assertEquals('xendit-inv-abc-123', $tagihan->xendit_invoice_id);
        $this->assertEquals('https://checkout.xendit.co/invoice/abc-123', $tagihan->xendit_invoice_url);
        $this->assertEquals('active', $tagihan->xendit_invoice_status);
    }

    public function test_listener_tidak_panggil_api_jika_invoice_sudah_ada(): void
    {
        $tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'total_tagihan' => 150000,
            'xendit_invoice_id' => 'existing-inv',
            'xendit_invoice_url' => 'https://existing.url',
        ]);

        Http::fake();

        $listener = app(BuatInvoiceXendit::class);
        $listener->handle(new TagihanDibuat($tagihan));

        Http::assertNothingSent();
    }
}
