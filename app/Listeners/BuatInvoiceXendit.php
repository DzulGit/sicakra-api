<?php

namespace App\Listeners;

use App\Events\TagihanDibuat;
use App\Models\Tagihan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BuatInvoiceXendit implements ShouldQueue
{
    public string $queue = 'xendit';
    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function handle(TagihanDibuat $event): void
    {
        $tagihan = $event->tagihan->fresh(['layananInternet.pelanggan']);

        if (!$tagihan || $tagihan->xendit_invoice_id) {
            return;
        }

        $pelanggan = $tagihan->layananInternet->pelanggan;

        $payload = [
            'external_id' => 'TGH-' . $tagihan->nomor_tagihan,
            'amount' => (float) $tagihan->total_tagihan,
            'description' => $this->buatDeskripsi($tagihan),
            'currency' => 'IDR',
            'invoice_duration' => config('services.xendit.invoice_duration', 259200),
            'payment_methods' => ['QRIS', 'BCA', 'MANDIRI', 'BRI', 'ALFAMART', 'INDOMARET'],
            'metadata' => [
                'tagihan_id' => $tagihan->id,
                'nomor_tagihan' => $tagihan->nomor_tagihan,
                'jumlah_bulan' => $tagihan->jumlah_bulan,
            ],
            'customer' => [
                'given_names' => $pelanggan->nama ?? $pelanggan->nomor_pelanggan,
                'mobile_number' => $pelanggan->nomor_hp,
            ],
        ];

        if ($pelanggan->email) {
            $payload['customer']['email'] = $pelanggan->email;
        }

        $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
            ->timeout(30)
            ->post('https://api.xendit.co/v2/invoices', $payload);

        if ($response->failed()) {
            Log::error('Xendit invoice creation failed', [
                'tagihan_id' => $tagihan->id,
                'response' => $response->body(),
            ]);
            $response->throw();
        }

        $body = $response->json();

        $tagihan->update([
            'xendit_invoice_id' => $body['id'],
            'xendit_invoice_url' => $body['invoice_url'],
            'xendit_invoice_status' => 'active',
            'xendit_invoice_expires_at' => $body['expiry_date'] ?? null,
        ]);
    }

    private function buatDeskripsi(Tagihan $tagihan): string
    {
        $periode = $tagihan->periode_bulan . '/' . $tagihan->periode_tahun;
        $paket = $tagihan->nama_paket_snapshot;

        $label = "Pembayaran {$paket} - Periode {$periode}";

        if ($tagihan->jumlah_bulan > 1) {
            $label .= " ({$tagihan->jumlah_bulan} bulan)";
        }

        return $label;
    }

    public function failed(TagihanDibuat $event, \Throwable $e): void
    {
        Log::error('BuatInvoiceXendit gagal setelah retries', [
            'tagihan_id' => $event->tagihan->id,
            'error' => $e->getMessage(),
        ]);
    }
}
