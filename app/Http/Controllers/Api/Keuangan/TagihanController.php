<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Filters\TagihanFilter;
use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use App\Services\GenerateTagihanService;
use Illuminate\Http\Request;

class TagihanController extends Controller
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
        private readonly GenerateTagihanService $generateTagihanService,
    ) {}

    public function index(TagihanFilter $filter)
    {
        $this->authorize('viewAny', Tagihan::class);

        return response()->json([
            'data' => $this->tagihanRepository->paginateSemua($filter),
        ]);
    }

    public function show(Tagihan $tagihan)
    {
        $this->authorize('view', $tagihan);

        $tagihan = $this->tagihanRepository->find(
            $tagihan->id,
            ['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran'],
        );

        return response()->json(['data' => $tagihan]);
    }

    public function ringkasanOmzet(Request $request)
    {
        $tahun = $request->integer('tahun', now()->year);

        $data = \App\Models\Tagihan::selectRaw('periode_bulan, SUM(total_tagihan) as total_omzet, COUNT(*) as jumlah_tagihan')
            ->where('periode_tahun', $tahun)
            ->where('status_pembayaran', \App\Enums\StatusPembayaranEnum::SUDAH_BAYAR)
            ->groupBy('periode_bulan')
            ->orderBy('periode_bulan')
            ->get();

        return response()->json(['data' => $data]);
    }

    /**
     * Generate manual tagihan bulan berjalan untuk semua layanan aktif milik
     * pelanggan. Idempotent — layanan yang tagihan periodenya sudah ada di-skip.
     *
     * Bisa request beberapa bulan sekaligus lewat `jumlah_bulan` (1-12),
     * total tagihan = harga * jumlah_bulan.
     */
    public function generateUntukPelanggan(Request $request, Pelanggan $pelanggan)
    {
        $this->authorize('create', Tagihan::class);

        $validated = $request->validate([
            'jumlah_bulan' => 'sometimes|integer|min:1|max:12',
        ]);

        $jumlahBulan = $validated['jumlah_bulan'] ?? 1;

        $layananAktif = $pelanggan->layananInternet()
            ->where('status', StatusLayananEnum::AKTIF)
            ->get();

        if ($layananAktif->isEmpty()) {
            return response()->json(['message' => 'Pelanggan tidak punya layanan aktif.'], 422);
        }

        $tagihanDibuat = [];

        foreach ($layananAktif as $layanan) {
            $tagihan = $this->generateTagihanService->generateUntukLayanan(
                $layanan,
                now()->month,
                now()->year,
                $jumlahBulan,
            );

            if ($tagihan) {
                $tagihanDibuat[] = $tagihan->load('layananInternet.paketInternet');
            }
        }

        if (empty($tagihanDibuat)) {
            return response()->json([
                'message' => $jumlahBulan > 1
                    ? "Tagihan {$jumlahBulan} bulan ini sudah ada untuk semua layanan."
                    : 'Tagihan bulan ini sudah ada untuk semua layanan.',
            ], 422);
        }

        return response()->json([
            'message' => 'Tagihan berhasil dibuat.',
            'data' => $tagihanDibuat,
        ], 201);
    }

    /**
     * Generate ulang / ubah jumlah bulan dari sebuah tagihan yang belum dibayar
     * (mis. semula 1 bulan, pelanggan berubah pikiran mau 12 bulan — atau sebaliknya).
     * Total tagihan ikut mengikuti = harga_snapshot * jumlah_bulan.
     */
    public function regenerate(Request $request, Tagihan $tagihan)
    {
        $this->authorize('regenerate', $tagihan);

        $validated = $request->validate([
            'jumlah_bulan' => 'required|integer|min:1|max:12',
        ]);

        $jumlahBulan = $validated['jumlah_bulan'];

        $tagihan->update([
            'jumlah_bulan' => $jumlahBulan,
            'total_tagihan' => $tagihan->harga_snapshot * $jumlahBulan,
        ]);

        return response()->json([
            'message' => 'Tagihan berhasil di-generate ulang.',
            'data' => $tagihan->fresh(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ]);
    }

    // Sengaja TIDAK ADA store()/update() — selain generate manual di atas,
    // Tagihan dibuat otomatis oleh sistem (GenerateTagihanMassalJob).
}
