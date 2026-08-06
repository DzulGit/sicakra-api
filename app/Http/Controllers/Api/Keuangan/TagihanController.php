<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Filters\TagihanFilter;
use App\Http\Controllers\Controller;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use App\Services\GenerateTagihanService;
use App\Services\SiklusPenagihanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagihanController extends Controller
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
        private readonly GenerateTagihanService $generateTagihanService,
        private readonly SiklusPenagihanService $siklusPenagihanService,
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

        $data = Tagihan::selectRaw('periode_bulan, SUM(total_tagihan) as total_omzet, COUNT(*) as jumlah_tagihan')
            ->where('periode_tahun', $tahun)
            ->where('status_pembayaran', StatusPembayaranEnum::SUDAH_BAYAR)
            ->groupBy('periode_bulan')
            ->orderBy('periode_bulan')
            ->get();

        return response()->json(['data' => $data]);
    }

    /**
     * Buat tagihan 1 bulan untuk siklus terdekat yang belum ditagih, per layanan
     * aktif milik pelanggan ini. Tombol 1-klik admin — TANPA input jumlah bulan.
     * Idempotent: siklus yang sudah punya tagihan di-skip.
     */
    public function generateUntukPelanggan(Request $request, Pelanggan $pelanggan)
    {
        $this->authorize('create', Tagihan::class);

        $layananAktif = $pelanggan->layananInternet()
            ->where('status', StatusLayananEnum::AKTIF)
            ->get();

        if ($layananAktif->isEmpty()) {
            return response()->json(['message' => 'Pelanggan tidak punya layanan aktif.'], 422);
        }

        $tagihanDibuat = [];

        foreach ($layananAktif as $layanan) {
            [$bulan, $tahun] = $this->periodeTerdekatBelumDitagih($layanan);

            $tagihan = $this->generateTagihanService->generateUntukLayanan($layanan, $bulan, $tahun);

            if ($tagihan) {
                $tagihanDibuat[] = $tagihan->load('layananInternet.paketInternet');
            }
        }

        if (empty($tagihanDibuat)) {
            return response()->json([
                'message' => 'Siklus terdekat sudah punya tagihan untuk semua layanan pelanggan ini.',
            ], 422);
        }

        return response()->json([
            'message' => 'Tagihan bulan terdekat berhasil dibuat.',
            'data' => $tagihanDibuat,
        ], 201);
    }

    /** Siklus (>= bulan berjalan) yang belum punya tagihan untuk layanan itu. */
    private function periodeTerdekatBelumDitagih(LayananInternet $layanan): array
    {
        $mulai = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $tanggal = $mulai->copy()->addMonthsNoOverflow($i);

            $sudahAda = Tagihan::where('layanan_internet_id', $layanan->id)
                ->where('periode_bulan', $tanggal->month)
                ->where('periode_tahun', $tanggal->year)
                ->exists();

            if (! $sudahAda) {
                return [$tanggal->month, $tanggal->year];
            }
        }

        return [$mulai->month, $mulai->year];
    }

    /**
     * Generate ulang / ubah jumlah bulan dari sebuah tagihan yang belum dibayar
     * (mis. semula 1 bulan, pelanggan berubah pikiran mau 12 bulan — atau sebaliknya).
     * Total tagihan ikut mengikuti = harga_snapshot * jumlah_bulan.
     *
     * Endpoint ini khusus Admin Keuangan/Super Admin (route-group peran:keuangan) —
     * limit retry 3x TIDAK berlaku di sini (unlimited). retry_count sengaja tidak
     * dinaikkan supaya perubahan oleh admin tidak menghabiskan jatah 3x pelanggan.
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

    /**
     * Bayar tunai di kantor (TAHAP 2). Tanpa Xendit: tagihan langsung LUNAS,
     * admin penerima dicatat, dan masa aktif layanan ditambah sesuai bulan.
     * `jumlah_bulan` boleh dikirim untuk sekaligus bayar beberapa bulan.
     */
    public function bayarTunai(Request $request, Tagihan $tagihan)
    {
        $this->authorize('regenerate', $tagihan);

        if ($tagihan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
            return response()->json(['message' => 'Tagihan sudah dibayar.'], 422);
        }

        $validated = $request->validate([
            'jumlah_bulan' => 'sometimes|integer|min:1|max:12',
        ]);

        $jumlahBulan = $validated['jumlah_bulan'] ?? $tagihan->jumlah_bulan;
        $admin = $request->user();

        $tagihanBaru = DB::transaction(function () use ($tagihan, $jumlahBulan, $admin) {
            $tagihan->update([
                'jumlah_bulan' => $jumlahBulan,
                'total_tagihan' => $tagihan->harga_snapshot * $jumlahBulan,
            ]);

            $pembayaran = $tagihan->pembayaran()->create([
                'metode_pembayaran' => 'tunai',
                'dibayar_oleh' => $admin->nama_lengkap,
                'jumlah_dibayar' => $tagihan->total_tagihan,
                'status' => StatusTransaksiEnum::BERHASIL,
                'dibayar_pada' => now(),
            ]);

            $tagihan->update([
                'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
                'dibayar_pada' => $pembayaran->dibayar_pada,
                'xendit_invoice_status' => 'paid',
            ]);

            $layanan = $tagihan->layananInternet;
            if ($layanan) {
                $layanan->update([
                    'tanggal_aktif' => $layanan->tanggal_aktif->copy()->addMonths($jumlahBulan),
                ]);

                // Jadwal penagihan dimajukan ke periode pertama yang belum terbayar,
                // supaya cron tidak tagih ulang bulan yang sudah dilunasi di muka.
                $this->siklusPenagihanService->majukanJadwalSetelahPembayaran($tagihan);
            }

            PembayaranBerhasil::dispatch($tagihan, $pembayaran);

            return $tagihan;
        });

        return response()->json([
            'message' => "Pembayaran tunai diterima (oleh {$admin->nama_lengkap}). Tagihan lunas.",
            'data' => $tagihanBaru->fresh(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ]);
    }

    // Sengaja TIDAK ADA store()/update() — selain generate manual di atas,
    // Tagihan dibuat otomatis oleh sistem (GenerateTagihanMassalJob).
}
