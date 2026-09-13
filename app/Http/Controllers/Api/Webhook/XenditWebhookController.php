<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Services\PembayaranAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        Log::info('Xendit webhook masuk', [
            'payload' => $request->all(),
        ]);

        /*
         * 1. Validasi callback token Xendit.
         */
        $token = $request->header('X-Callback-Token');

        $expectedToken = config(
            'services.xendit.webhook_verification_token'
        );

        if (
            !$token
            || !$expectedToken
            || !hash_equals($expectedToken, $token)
        ) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $payload = $request->all();

        $externalId = $payload['external_id'] ?? null;
        $xenditInvoiceId = $payload['id'] ?? null;
        $status = strtoupper($payload['status'] ?? '');

        /*
         * 2. Webhook harus memiliki external_id.
         *
         * External ID kita sekarang:
         *
         * PAY-123
         *
         * dimana 123 adalah ID Pembayaran.
         */
        if (!$externalId) {
            return response()->json([
                'message' => 'Invalid external_id',
            ], 400);
        }

        if (!str_starts_with($externalId, 'PAY-')) {
            return response()->json([
                'message' => 'Unsupported external_id',
            ], 400);
        }

        /*
         * 3. Cari Pembayaran yang memang membuat invoice tersebut.
         *
         * Jangan membuat Pembayaran baru di webhook.
         */
        $pembayaran = Pembayaran::query()
            ->where('provider', 'xendit')
            ->where('provider_external_id', $externalId)
            ->first();

        if (!$pembayaran) {
            return response()->json([
                'message' => 'Pembayaran not found',
            ], 404);
        }

        /*
         * 4. Simpan informasi provider terlebih dahulu.
         *
         * provider_reference = ID invoice Xendit
         * provider_external_id = PAY-{pembayaran_id}
         */
        $pembayaran->update([
            'provider_reference' =>
                $xenditInvoiceId
                ?? $pembayaran->provider_reference,

            'provider_external_id' =>
                $externalId,

            'provider_status' =>
                strtolower($status),

            'payload_webhook' =>
                $payload,
        ]);

        /*
         * 5. PAID / SETTLED
         */
        if (in_array($status, ['PAID', 'SETTLED'], true)) {
            return $this->prosesPembayaranBerhasil(
                $pembayaran->fresh(),
                $payload
            );
        }

        /*
         * 6. EXPIRED
         */
        if ($status === 'EXPIRED') {
            return $this->prosesPembayaranExpired(
                $pembayaran->fresh(),
                $payload
            );
        }

        /*
         * Status lain seperti PENDING tidak dianggap gagal.
         * Kita simpan status provider dan selesai.
         */
        return response()->json([
            'message' => 'Webhook diterima.',
        ]);
    }

    private function prosesPembayaranBerhasil(
        Pembayaran $pembayaran,
        array $payload
    ): JsonResponse {
        $hasil = DB::transaction(function () use ($pembayaran, $payload) {
            $pembayaran = Pembayaran::query()
                ->lockForUpdate()
                ->findOrFail($pembayaran->id);

            /*
            * Kalau webhook PAID yang sama dikirim ulang,
            * jangan proses allocation ulang.
            */
            if (
                $pembayaran->status ===
                StatusTransaksiEnum::BERHASIL
            ) {
                $pembayaran->update([
                    'provider_status' => 'paid',
                    'payload_webhook' => $payload,
                ]);

                return [
                    'pembayaran' => $pembayaran->fresh([
                        'pelanggan',
                        'alokasiTagihan.tagihan',
                        'mutasiSaldoKredit',
                    ]),
                    'baru_berhasil' => false,
                ];
            }

            /*
            * Pembayaran hanya boleh diproses dari PENDING.
            */
            if (
                $pembayaran->status !==
                StatusTransaksiEnum::PENDING
            ) {
                return [
                    'pembayaran' => $pembayaran,
                    'baru_berhasil' => false,
                ];
            }

            $jumlahAktual = isset($payload['paid_amount'])
                ? round((float) $payload['paid_amount'], 2)
                : (float) $pembayaran->jumlah_dibayar;

            if ($jumlahAktual <= 0) {
                throw new \RuntimeException(
                    'Jumlah pembayaran dari webhook tidak valid.'
                );
            }

            /*
            * PENTING:
            *
            * Status BERHASIL dan allocation dilakukan
            * dalam transaction database yang sama.
            */
            $pembayaran->update([
                'jumlah_dibayar' => $jumlahAktual,
                'status' => StatusTransaksiEnum::BERHASIL,
                'payload_webhook' => $payload,
                'dibayar_pada' => now(),
                'provider_status' => 'paid',
            ]);

            /*
            * Kalau allocation gagal, exception akan keluar
            * dari transaction dan perubahan BERHASIL di atas
            * ikut di-rollback.
            */
            $pembayaran = $this->pembayaranAllocationService
                ->selesaikanPembayaran($pembayaran);

            return [
                'pembayaran' => $pembayaran,
                'baru_berhasil' => true,
            ];
        });

        $pembayaranBerhasil = $hasil['pembayaran'];

        /*
        * Event dikirim setelah transaction berhasil commit.
        */
        if ($hasil['baru_berhasil']) {
            PembayaranBerhasil::dispatch(
                $pembayaranBerhasil
            );
        }

        return response()->json([
            'message' => $hasil['baru_berhasil']
                ? 'Pembayaran berhasil diproses.'
                : 'Webhook pembayaran sudah pernah diproses.',
            'data' => $pembayaranBerhasil->fresh([
                'pelanggan',
                'alokasiTagihan.tagihan',
                'mutasiSaldoKredit',
            ]),
        ]);
    }

    private function prosesPembayaranExpired(
        Pembayaran $pembayaran,
        array $payload
    ): JsonResponse {
        DB::transaction(function () use (
            $pembayaran,
            $payload
        ) {
            $pembayaran = Pembayaran::query()
                ->lockForUpdate()
                ->findOrFail($pembayaran->id);

            /*
             * Kalau sudah berhasil, webhook EXPIRED yang terlambat
             * tidak boleh membalikkan transaksi menjadi gagal.
             */
            if (
                $pembayaran->status ===
                StatusTransaksiEnum::BERHASIL
            ) {
                return;
            }

            $pembayaran->update([
                'status' => StatusTransaksiEnum::GAGAL,

                'provider_status' => 'expired',

                'payload_webhook' => $payload,
            ]);
        });

        return response()->json([
            'message' => 'Invoice pembayaran kedaluwarsa.',
        ]);
    }
}