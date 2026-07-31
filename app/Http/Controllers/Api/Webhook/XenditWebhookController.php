<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class XenditWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->header('X-Callback-Token');

        if (!$token || !hash_equals(config('services.xendit.webhook_verification_token'), $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        $externalId = $payload['external_id'] ?? '';
        $xenditInvoiceId = $payload['id'] ?? '';
        $status = $payload['status'] ?? '';

        $tagihanPrefix = 'TGH-';
        $nomorTagihan = str_starts_with($externalId, $tagihanPrefix)
            ? substr($externalId, strlen($tagihanPrefix))
            : null;

        if (!$nomorTagihan) {
            return response()->json(['message' => 'Invalid external_id'], 400);
        }

        $tagihan = Tagihan::where('nomor_tagihan', $nomorTagihan)->first();

        if (!$tagihan) {
            return response()->json(['message' => 'Tagihan not found'], 404);
        }

        $simpanPembayaran = function () use ($tagihan, $payload, $xenditInvoiceId, $status) {
            return $tagihan->pembayaran()->create([
                'metode_pembayaran' => $payload['payment_method'] ?? null,
                'jumlah_dibayar' => $payload['paid_amount'] ?? $payload['amount'] ?? $tagihan->total_tagihan,
                'referensi_xendit' => $xenditInvoiceId,
                'status' => $status === 'PAID' || $status === 'SETTLED'
                    ? StatusTransaksiEnum::BERHASIL
                    : StatusTransaksiEnum::GAGAL,
                'payload_webhook' => $payload,
                'dibayar_pada' => $status === 'PAID' || $status === 'SETTLED' ? now() : null,
            ]);
        };

        DB::transaction(function () use ($tagihan, $payload, $status, $simpanPembayaran) {
            $pembayaran = $simpanPembayaran();

            if ($status === 'PAID' || $status === 'SETTLED') {
                if ($tagihan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
                    return;
                }

                $tagihan->update([
                    'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
                    'dibayar_pada' => $pembayaran->dibayar_pada,
                ]);

                $layanan = $tagihan->layananInternet;
                if ($layanan && $tagihan->jumlah_bulan >= 1) {
                    $layanan->update([
                        'tanggal_aktif' => $layanan->tanggal_aktif->copy()->addMonths($tagihan->jumlah_bulan),
                    ]);
                }

                PembayaranBerhasil::dispatch($tagihan, $pembayaran);
            }

            if ($status === 'EXPIRED') {
                $tagihan->update([
                    'status_pembayaran' => StatusPembayaranEnum::KEDALUWARSA,
                    'xendit_invoice_status' => 'expired',
                ]);
            }
        });

        return response()->json(['message' => 'OK']);
    }
}
