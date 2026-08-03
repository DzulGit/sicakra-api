<?php

namespace App\Listeners;

use App\Events\TagihanDibuat;
use App\Models\Tagihan;
use App\Services\XenditInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class BuatInvoiceXendit implements ShouldQueue
{
    public string $queue = 'xendit';
    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(private readonly XenditInvoiceService $xenditInvoiceService) {}

    public function handle(TagihanDibuat $event): void
    {
        $tagihan = $event->tagihan->fresh(['layananInternet.pelanggan']);

        if (!$tagihan || $tagihan->xendit_invoice_id) {
            return;
        }

        $body = $this->xenditInvoiceService->buatInvoice($tagihan);

        $tagihan->update([
            'xendit_invoice_id' => $body['id'],
            'xendit_external_id' => $body['external_id'] ?? null,
            'xendit_invoice_url' => $body['invoice_url'],
            'xendit_invoice_status' => 'active',
            'xendit_invoice_expires_at' => $body['expiry_date'] ?? null,
        ]);
    }

    public function failed(TagihanDibuat $event, \Throwable $e): void
    {
        Log::error('BuatInvoiceXendit gagal setelah retries', [
            'tagihan_id' => $event->tagihan->id,
            'error' => $e->getMessage(),
        ]);
    }
}