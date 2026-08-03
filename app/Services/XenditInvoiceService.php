<?php

namespace App\Services;

use App\Models\Tagihan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Membuat invoice Xendit untuk sebuah tagihan. Dipakai baik oleh listener
 * async (auto/massal) maupun oleh endpoint regenerate (sinkron, supaya URL
 * baru bisa langsung dikembalikan ke pelanggan).
 */
class XenditInvoiceService
{
    public function buatInvoice(Tagihan $tagihan): array
    {
        $tagihan = $tagihan->fresh(['layananInternet.pelanggan']);
        $pelanggan = $tagihan->layananInternet->pelanggan;

        $payload = [
            'external_id' => $this->buatExternalId($tagihan),
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
                'given_names' => $pelanggan->nama_lengkap ?? $pelanggan->nomor_pelanggan,
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

        return $response->json();
    }

    /**
     * external_id dipakai untuk trace di sisi Xendit. Retry pertama (retry_count=0)
     * tanpa suffix; regenerate berikutnya diberi suffix -N agar tidak duplikat &
     * unik. Webhook men-strip suffix ini sebelum lookup nomor tagihan.
     */
    public function buatExternalId(Tagihan $tagihan): string
    {
        $retry = max(0, (int) $tagihan->xendit_invoice_retry_count);
        $suffix = $retry > 0 ? '-' . $retry : '';

        return 'TGH-' . $tagihan->nomor_tagihan . $suffix;
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
}