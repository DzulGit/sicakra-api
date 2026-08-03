<?php

namespace Tests\Unit\Services;

use App\Models\Tagihan;
use App\Services\XenditInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XenditInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_id_tanpa_retry_tidak_ber_suffix(): void
    {
        $tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'xendit_invoice_retry_count' => 0,
        ]);

        $this->assertEquals('TGH-INV000001', app(XenditInvoiceService::class)->buatExternalId($tagihan));
    }

    public function test_external_id_di_retry_kedua_memakai_suffix(): void
    {
        $tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'xendit_invoice_retry_count' => 2,
        ]);

        $this->assertEquals('TGH-INV000001-2', app(XenditInvoiceService::class)->buatExternalId($tagihan));
    }
}
