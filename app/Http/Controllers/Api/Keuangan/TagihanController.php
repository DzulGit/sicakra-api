<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Filters\TagihanFilter;
use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use App\Services\GenerateTagihanService;
use App\Services\SiklusPenagihanService;
use App\Services\XenditInvoiceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagihanController extends Controller
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
        private readonly GenerateTagihanService $generateTagihanService,
        private readonly SiklusPenagihanService $siklusPenagihanService,
        private readonly XenditInvoiceService $xenditInvoiceService,
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
     * Buat tagihan untuk periode tertentu yang dipilih admin (pilihan bulan tagihan)
     * — fitur DARURAT saja. Preview & konfirmasi ditangani frontend sebelum mengirim
     * request ini. Periode yang sudah ter-cover tagihan (UNPAID maupun PAID) DITOLAK
     * dengan error jelas; sistem penagihan harian normal dijalankan cron job.
     */
    public function generateUntukPelanggan(Request $request, Pelanggan $pelanggan)
    {
        $this->authorize('create', Tagihan::class);

        $validated = $request->validate([
            'periode_bulan' => 'required|integer|min:1|max:12',
            'periode_tahun' => 'required|integer|min:2020|max:2100',
            'jumlah_hari_jatuh_tempo' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ]);

        $periodeBulan = (int) $validated['periode_bulan'];
        $periodeTahun = (int) $validated['periode_tahun'];
        $jumlahHariJatuhTempo = (int) ($validated['jumlah_hari_jatuh_tempo'] ?? 7);

        $tanggalJatuhTempo = Carbon::today()->addDays($jumlahHariJatuhTempo);

        $layananAktif = $pelanggan->layananInternet()
            ->where('status', StatusLayananEnum::AKTIF)
            ->get();

        if ($layananAktif->isEmpty()) {
            return response()->json(['message' => 'Pelanggan tidak punya layanan aktif.'], 422);
        }

        // Perketat: generate manual hanya boleh untuk periode yang BELUM diterbitkan
        // — baik yang masih belum bayar maupun sudah lunas sekalipun. Kalau sudah ada
        // record yang meng-cover periode pilihan, tolak di muka (jangan push terlanjur
        // menghasilkan null diam-diam seperti perilaku idempotent lama).
        foreach ($layananAktif as $layanan) {
            if ($this->generateTagihanService->periodeSudahTercover($layanan, $periodeBulan, $periodeTahun)) {
                return response()->json([
                    'message' => "Tagihan untuk periode ini sudah diterbitkan (bulan {$periodeBulan}/{$periodeTahun}).",
                ], 422);
            }
        }

        $tagihanDibuat = [];

        foreach ($layananAktif as $layanan) {
            $tagihan = $this->generateTagihanService->generateUntukLayanan(
                $layanan,
                $periodeBulan,
                $periodeTahun,
                tanggalJatuhTempo: $tanggalJatuhTempo,
            );

            if ($tagihan) {
                $tagihanDibuat[] = $tagihan->load('layananInternet.paketInternet');
            }
        }

        if (empty($tagihanDibuat)) {
            return response()->json([
                'message' => "Tagihan periode {$periodeBulan}/{$periodeTahun} sudah ter-cover untuk semua layanan pelanggan ini.",
            ], 422);
        }

        return response()->json([
            'message' => "Tagihan periode {$periodeBulan}/{$periodeTahun} berhasil dibuat.",
            'data' => $tagihanDibuat,
        ], 201);
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

    /**
     * Perbarui link pembayaran (regenerate invoice Xendit) untuk tagihan yang
     * link-nya kadaluwarsa / belum dibayar. Dipakai Admin Keuangan saat pelanggan
     * kehabisan link bayar; durasi invoice baru 7 hari. Tagihan yang sudah LUNAS
     * tidak boleh di-perbarui.
     */
    public function perbaruiLink(Tagihan $tagihan)
    {
        $this->authorize('create', Tagihan::class);

        if ($tagihan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
            return response()->json(['message' => 'Tagihan sudah dibayar.'], 422);
        }

        // Reset state invoice lama, naikkan retry invoice (biar external_id baru
        // unik di Xendit), lalu minta invoice baru dengan durasi 7 hari.
        $tagihan->update([
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'xendit_invoice_id' => null,
            'xendit_external_id' => null,
            'xendit_invoice_url' => null,
            'xendit_invoice_status' => 'expired',
            'xendit_invoice_expires_at' => null,
            'xendit_invoice_retry_count' => $tagihan->xendit_invoice_retry_count + 1,
        ]);

        $body = $this->xenditInvoiceService->buatInvoice($tagihan->fresh(), durasiHari: 7);

        $tagihan->update([
            'xendit_invoice_id' => $body['id'],
            'xendit_external_id' => $body['external_id'] ?? null,
            'xendit_invoice_url' => $body['invoice_url'],
            'xendit_invoice_status' => 'active',
            'xendit_invoice_expires_at' => $body['expiry_date'] ?? null,
        ]);

        return response()->json([
            'message' => 'Link pembayaran berhasil diperbarui.',
            'data' => $tagihan->fresh(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ]);
    }

    // Sengaja TIDAK ADA store()/update() — selain generate manual di atas,
    // Tagihan dibuat otomatis oleh sistem (GenerateTagihanMassalJob).
}
