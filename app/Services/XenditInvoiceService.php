<?php

namespace App\Services;

use App\Models\Pembayaran;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenditInvoiceService
{
    /**
     * Membuat invoice Xendit untuk sebuah transaksi pembayaran.
     *
     * Satu pembayaran dapat digunakan untuk membayar beberapa tagihan,
     * sehingga invoice Xendit tidak lagi dibuat berdasarkan Tagihan.
     */
    public function buatInvoice(
        Pembayaran $pembayaran,
        ?int $durasiHari = null
    ): array {
        $pembayaran = $pembayaran->fresh([
            'pelanggan',
        ]);

        if (!$pembayaran) {
            throw new RuntimeException(
                'Pembayaran tidak ditemukan.'
            );
        }

        if (!$pembayaran->pelanggan_id) {
            throw new RuntimeException(
                'Pembayaran tidak memiliki pelanggan.'
            );
        }

        $pelanggan = $pembayaran->pelanggan;

        if (!$pelanggan) {
            throw new RuntimeException(
                'Pelanggan pembayaran tidak ditemukan.'
            );
        }

        $jumlah = (float) $pembayaran->jumlah_dibayar;

        if ($jumlah <= 0) {
            throw new RuntimeException(
                'Jumlah pembayaran harus lebih besar dari 0.'
            );
        }

        /*
         * Satu Pembayaran = satu invoice Xendit.
         *
         * External ID dibuat berdasarkan ID pembayaran agar:
         * - unik
         * - mudah dilacak dari webhook
         * - tidak bergantung pada nomor tagihan
         */
        $externalId = $this->buatExternalId($pembayaran);

        $payload = [
            'external_id' => $externalId,

            'amount' => $jumlah,

            'description' => $this->buatDeskripsi($pembayaran),

            'currency' => 'IDR',

            'invoice_duration' => $durasiHari
                ? $durasiHari * 86400
                : config(
                    'services.xendit.invoice_duration',
                    864000
                ),

            'payment_methods' => [
                'QRIS',
                'BCA',
                'MANDIRI',
                'BRI',
                'ALFAMART',
                'INDOMARET',
            ],

            'metadata' => [
                'pembayaran_id' => $pembayaran->id,
                'pelanggan_id' => $pelanggan->id,
                'jumlah_dibayar' => $jumlah,
            ],

            'customer' => [
                'given_names' =>
                    $pelanggan->nama_lengkap
                    ?? $pelanggan->nomor_pelanggan,

                'mobile_number' =>
                    $pelanggan->nomor_hp,
            ],
        ];

        if ($pelanggan->email) {
            $payload['customer']['email'] = $pelanggan->email;
        }

        $response = Http::withBasicAuth(
            config('services.xendit.secret_key'),
            ''
        )
            ->timeout(30)
            ->post(
                'https://api.xendit.co/v2/invoices',
                $payload
            );

        if ($response->failed()) {
            Log::error(
                'Xendit invoice creation failed',
                [
                    'pembayaran_id' => $pembayaran->id,
                    'pelanggan_id' => $pelanggan->id,
                    'amount' => $jumlah,
                    'response' => $response->body(),
                ]
            );

            $response->throw();
        }

        $body = $response->json();

        if (!is_array($body) || empty($body['id'])) {
            Log::error(
                'Xendit returned invalid invoice response',
                [
                    'pembayaran_id' => $pembayaran->id,
                    'response' => $body,
                ]
            );

            throw new RuntimeException(
                'Response invoice Xendit tidak valid.'
            );
        }

        return $body;
    }

    /**
     * External ID untuk Xendit.
     *
     * Contoh:
     * PAY-123
     *
     * ID pembayaran dipakai sebagai sumber identitas utama,
     * bukan nomor tagihan.
     */
    public function buatExternalId(
        Pembayaran $pembayaran
    ): string {
        return 'PAY-' . $pembayaran->id;
    }

    /**
     * Deskripsi invoice Xendit.
     *
     * Karena pembayaran belum dialokasikan ketika invoice dibuat,
     * deskripsi dibuat berdasarkan pelanggan dan nominal pembayaran.
     */
    private function buatDeskripsi(
        Pembayaran $pembayaran
    ): string {
        $pelanggan = $pembayaran->pelanggan;

        $namaPelanggan =
            $pelanggan?->nama_lengkap
            ?? $pelanggan?->nomor_pelanggan
            ?? 'Pelanggan';

        return sprintf(
            'Pembayaran tagihan %s - Rp%s',
            $namaPelanggan,
            number_format(
                (float) $pembayaran->jumlah_dibayar,
                0,
                ',',
                '.'
            )
        );
    }
}