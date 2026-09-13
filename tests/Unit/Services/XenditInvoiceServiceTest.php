<?php

namespace Tests\Unit\Services;

use App\Models\Pembayaran;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class XenditInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_id_menggunakan_id_pembayaran(): void
    {
        $pembayaran = Pembayaran::factory()->create();

        $this->assertEquals(
            'PAY-' . $pembayaran->id,
            app(\App\Services\XenditInvoiceService::class)->buatExternalId($pembayaran)
        );
    }
}