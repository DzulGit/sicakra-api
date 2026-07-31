<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Events\TagihanDibuat;
use App\Filters\TagihanFilter;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagihanSayaController extends Controller
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
    ) {}

    public function index(Request $request, TagihanFilter $filter)
    {
        $this->authorize('viewAny', Tagihan::class);

        return response()->json([
            'data' => $this->tagihanRepository->paginateUntukPelanggan($request->user()->id, $filter),
        ]);
    }

    public function show(Tagihan $tagihan)
    {
        $this->authorize('view', $tagihan);

        $tagihan = $this->tagihanRepository->find($tagihan->id, ['layananInternet', 'pembayaran']);

        return response()->json(['data' => $tagihan]);
    }

    public function bayar(Request $request, Tagihan $tagihan): JsonResponse
    {
        $this->authorize('view', $tagihan);

        if ($tagihan->status_pembayaran === \App\Enums\StatusPembayaranEnum::SUDAH_BAYAR) {
            return response()->json(['message' => 'Tagihan sudah dibayar.'], 422);
        }

        if ($tagihan->xendit_invoice_retry_count >= 3) {
            return response()->json(['message' => 'Maksimal percobaan pembayaran telah tercapai. Hubungi customer service.'], 422);
        }

        $validated = $request->validate([
            'jumlah_bulan' => 'sometimes|integer|min:1|max:12',
        ]);

        $jumlahBulan = $validated['jumlah_bulan'] ?? 1;

        if ($jumlahBulan > 1) {
            $totalBaru = $tagihan->harga_snapshot * $jumlahBulan;
            $tagihan->update(['jumlah_bulan' => $jumlahBulan, 'total_tagihan' => $totalBaru]);
        }

        $tagihan->update([
            'status_pembayaran' => \App\Enums\StatusPembayaranEnum::BELUM_BAYAR,
            'xendit_invoice_id' => null,
            'xendit_invoice_url' => null,
            'xendit_invoice_status' => null,
            'xendit_invoice_expires_at' => null,
            'xendit_invoice_retry_count' => $tagihan->xendit_invoice_retry_count + 1,
        ]);

        TagihanDibuat::dispatch($tagihan->fresh(['layananInternet.pelanggan']));

        return response()->json(['message' => 'OK', 'data' => $tagihan->fresh(['pembayaran'])]);
    }
}