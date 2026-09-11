<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Events\TagihanDibuat;
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

    public function pendaftarBaru(Request $request)
    {
        $this->authorize('create', Tagihan::class);

        $perPage = $request->integer('per_page', 10);

        $pelanggan = Pelanggan::query()
            ->whereHas('layananInternet', function ($query) {
                $query
                    ->where('status', StatusLayananEnum::AKTIF)
                    ->whereDoesntHave('tagihan');
            })
            ->with([
                'layananInternet' => function ($query) {
                    $query
                        ->where('status', StatusLayananEnum::AKTIF)
                        ->whereDoesntHave('tagihan')
                        ->with('paketInternet');
                },
            ])
            ->orderBy('nama_lengkap')
            ->paginate($perPage);

        return response()->json($pelanggan);
    }

    /**
     * Buat tagihan untuk periode tertentu yang dipilih admin (pilihan bulan tagihan)
     * — fitur DARURAT saja. Preview & konfirmasi ditangani frontend sebelum mengirim
     * request ini. Periode yang sudah ter-cover tagihan (UNPAID maupun PAID) DITOLAK
     * dengan error jelas.
     */
    public function generateUntukPelanggan(Request $request, Pelanggan $pelanggan)
    {
        $this->authorize('create', Tagihan::class);

        $validated = $request->validate([
            'periode_bulan' => 'required|integer|min:1|max:12',
            'periode_tahun' => 'required|integer|min:2020|max:2100',
        ]);

        $periodeBulan = (int) $validated['periode_bulan'];
        $periodeTahun = (int) $validated['periode_tahun'];

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
     * Preview tagihan pertama untuk pelanggan aktif.
     *
     * Tidak membuat record tagihan.
     * Mengembalikan perhitungan prorata dan full agar Keuangan
     * bisa memilih mode sebelum menerbitkan tagihan.
     */
    public function previewTagihanPertama(
        Request $request,
        Pelanggan $pelanggan
    ) {
        $this->authorize('create', Tagihan::class);

        $layananAktif = $pelanggan->layananInternet()
            ->where('status', StatusLayananEnum::AKTIF)
            ->get();

        if ($layananAktif->isEmpty()) {
            return response()->json([
                'message' => 'Pelanggan tidak punya layanan aktif.',
            ], 422);
        }

        $data = [];

        foreach ($layananAktif as $layanan) {
            if (Tagihan::where('layanan_internet_id', $layanan->id)->exists()) {
                continue;
            }

            $prorata = $this->generateTagihanService->hitungTagihanPertama(
                $layanan,
                'prorata'
            );

            $full = $this->generateTagihanService->hitungTagihanPertama(
                $layanan,
                'full'
            );

            $data[] = [
                'layanan_internet_id' => $layanan->id,
                'prorata' => $prorata,
                'full' => $full,
            ];
        }

        if (empty($data)) {
            return response()->json([
                'message' => 'Pelanggan ini sudah memiliki tagihan.',
            ], 422);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Generate tagihan pertama secara manual oleh Keuangan.
     *
     * Tagihan pertama hanya dibuat sekali untuk layanan.
     * Mode:
     * - prorata
     * - full
     *
     * Nominal hasil perhitungan boleh diubah manual oleh Keuangan.
     */
    public function generateTagihanPertama(
        Request $request,
        Pelanggan $pelanggan
    ) {
        $this->authorize('create', Tagihan::class);

        $validated = $request->validate([
            'layanan_internet_id' => [
                'required',
                'integer',
            ],
            'mode' => [
                'required',
                'string',
                'in:prorata,full',
            ],
            'nominal_manual' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ]);

        $layanan = $pelanggan->layananInternet()
            ->where('id', $validated['layanan_internet_id'])
            ->where('status', StatusLayananEnum::AKTIF)
            ->first();

        if (! $layanan) {
            return response()->json([
                'message' => 'Layanan aktif tidak ditemukan untuk pelanggan ini.',
            ], 422);
        }

        if (
            Tagihan::where('layanan_internet_id', $layanan->id)
                ->exists()
        ) {
            return response()->json([
                'message' => 'Tagihan pertama untuk layanan ini sudah pernah dibuat.',
            ], 422);
        }

        $tagihan = $this->generateTagihanService->generateTagihanPertama(
            $layanan,
            $validated['mode'],
            isset($validated['nominal_manual'])
                ? (float) $validated['nominal_manual']
                : null,
        );

        if (! $tagihan) {
            return response()->json([
                'message' => 'Tagihan pertama gagal dibuat.',
            ], 422);
        }

        return response()->json([
            'message' => 'Tagihan pertama berhasil dibuat.',
            'data' => $tagihan->load([
                'layananInternet.paketInternet',
                'layananInternet.pelanggan',
                'pembayaran',
            ]),
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
    // Tagihan draft dibuat oleh cron bulanan (tagihan:generate-draft).

    /**
     * List tagihan draft (belum_diterbitkan) untuk halaman Terbitkan Tagihan.
     * Hanya tampilkan tagihan dari pelanggan yang AKTIF dan pernah punya tagihan sebelumnya.
     */
    public function draftIndex(Request $request)
    {
        $this->authorize('viewAny', Tagihan::class);

        $perPage = $request->integer('per_page', 20);
        $periodeBulan = $request->integer('periode_bulan');
        $periodeTahun = $request->integer('periode_tahun');

        $query = Tagihan::draft()
            ->whereHas('layananInternet', function ($q) {
                $q->where('status', StatusLayananEnum::AKTIF)
                    ->whereHas('pelanggan', function ($pq) {
                        // Validasi: pelanggan harus pernah punya tagihan sebelumnya
                        $pq->whereHas('layananInternet.tagihan', function ($tq) {
                            $tq->where('status_pembayaran', '!=', StatusPembayaranEnum::BELUM_DITERBITKAN);
                        });
                    });
            })
            ->with(['layananInternet.paketInternet', 'layananInternet.pelanggan']);

        if ($periodeBulan) {
            $query->where('periode_bulan', $periodeBulan);
        }
        if ($periodeTahun) {
            $query->where('periode_tahun', $periodeTahun);
        }

        $tagihan = $query->latest()->paginate($perPage);

        return response()->json(['data' => $tagihan]);
    }

    /**
     * Terbitkan tagihan draft — ubah status ke belum_bayar & dispatch TagihanDibuat event
     * untuk trigger pembuatan Xendit invoice + notifikasi ke pelanggan.
     *
     * Validasi: tagihan harus belum_diterbitkan, dan pelanggan harus pernah punya tagihan.
     */
    public function terbitkan(Request $request)
    {
        $this->authorize('create', Tagihan::class);

        $validated = $request->validate([
            'tagihan_ids' => 'required|array|min:1',
            'tagihan_ids.*' => 'required|integer|exists:tagihan,id',
            'nominal' => 'nullable|array',
            'nominal.*' => 'required|numeric|min:0',
        ]);

        $tagihanIds = $validated['tagihan_ids'];
        $nominals = $validated['nominal'] ?? [];

        $berhasil = 0;
        $gagal = 0;
        $pesanGagal = [];

        DB::beginTransaction();

        try {
            foreach ($tagihanIds as $tagihanId) {
                $tagihan = Tagihan::find($tagihanId);

                if (! $tagihan || $tagihan->status_pembayaran !== StatusPembayaranEnum::BELUM_DITERBITKAN) {
                    $gagal++;
                    $pesanGagal[] = "Tagihan #{$tagihanId} tidak valid atau sudah diterbitkan.";
                    continue;
                }

                // Validasi: pelanggan harus pernah punya tagihan sebelumnya
                $layanan = $tagihan->layananInternet;
                if (! $layanan || ! $layanan->pelanggan) {
                    $gagal++;
                    $pesanGagal[] = "Tagihan #{$tagihanId}: pelanggan tidak ditemukan.";
                    continue;
                }

                $pelanggan = $layanan->pelanggan;
                $sudahPunyaTagihan = Tagihan::where('layanan_internet_id', $layanan->id)
                    ->where('id', '!=', $tagihanId)
                    ->where('status_pembayaran', '!=', StatusPembayaranEnum::BELUM_DITERBITKAN)
                    ->exists();

                if (! $sudahPunyaTagihan) {
                    $gagal++;
                    $pesanGagal[] = "Pelanggan {$pelanggan->nama_lengkap} belum pernah punya tagihan sebelumnya.";
                    continue;
                }

                // Update nominal jika dikirim
                if (isset($nominals[$tagihanId])) {
                    $tagihan->update([
                        'total_tagihan' => $nominals[$tagihanId],
                    ]);
                }

                // Ubah status & dispatch event
                $tagihan->update([
                    'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
                ]);

                TagihanDibuat::dispatch($tagihan);

                $berhasil++;
            }

            DB::commit();

            $pesan = "{$berhasil} tagihan berhasil diterbitkan.";
            if ($gagal > 0) {
                $pesan .= " {$gagal} gagal: " . implode('; ', $pesanGagal);
            }

            return response()->json([
                'message' => $pesan,
                'berhasil' => $berhasil,
                'gagal' => $gagal,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menerbitkan tagihan: ' . $e->getMessage()], 500);
        }
    }
}
