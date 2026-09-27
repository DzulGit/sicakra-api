<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Exports\ResellerLaporanExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\DaftarTransaksiResellerRequest;
use App\Http\Requests\Operasional\LaporanResellerRequest;
use App\Http\Requests\Operasional\SimpanResellerRequest;
use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\ShadowSesi;
use App\Models\Tagihan;
use App\Repositories\Contracts\AdminRepositoryInterface;
use App\Services\KtpStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ResellerController extends Controller
{
    public function __construct(
        private readonly AdminRepositoryInterface $adminRepository,
    ) {}

    public function index()
    {
        $this->authorize('viewAny', Admin::class);

        $resellers = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->withCount(['pelanggan'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $resellers]);
    }

    public function store(SimpanResellerRequest $request)
    {
        $this->authorize('create', Admin::class);

        $data = $request->validated();
        $data['peran'] = PeranAdminEnum::RESELLER;
        $data['status_aktif'] = true;
        $data['dibuat_oleh'] = $request->user()->id;

        $reseller = $this->adminRepository->create($data);

        return response()->json(['data' => $reseller], 201);
    }

    public function show(Admin $reseller)
    {
        $this->authorize('view', $reseller);

        $reseller->loadCount('pelanggan');

        return response()->json(['data' => $reseller]);
    }

    /** Pantau pelanggan milik reseller — read-only (tanpa aksi tulis). */
    public function pelanggan(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $pelanggan = $reseller->pelanggan()
            ->with([
                'layananInternet.paketInternet',
                'layananInternet.tagihan.pembayaran',
            ])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $pelanggan]);
    }

    public function pelangganDetail(Admin $reseller, \App\Models\Pelanggan $pelanggan)
    {
        $this->authorize('lihatPelanggan', $reseller);

        // Pastikan pelanggan memang milik reseller tersebut.
        if ($pelanggan->reseller_id !== $reseller->id) {
            abort(404);
        }

        $pelanggan->load([
            'layananInternet.paketInternet',
            'layananInternet.tagihan.pembayaran',
        ]);

        return response()->json([
            'data' => $pelanggan,
        ]);
    }

    /**
     * Preview foto KTP pelanggan milik reseller (admin internal) — otorisasi
     * sama persis seperti pelangganDetail (ResellerPolicy::lihatPelanggan),
     * path berasal dari record pelanggan yang sudah diverifikasi scope-nya.
     */
    public function fotoKtpPelanggan(Admin $reseller, Pelanggan $pelanggan): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('lihatPelanggan', $reseller);

        if ($pelanggan->reseller_id !== $reseller->id) {
            abort(404);
        }

        return KtpStorageService::responGambar($pelanggan);
    }

    /** Pantau paket internet yang dibuat reseller — read-only. */
    public function paket(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $paket = PaketInternet::where('reseller_id', $reseller->id)
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $paket]);
    }

    /** Pantau seluruh tagihan reseller kepada pelanggannya — read-only. */
    public function tagihan(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $tagihan = Tagihan::whereHas(
            'layananInternet.pelanggan',
            fn ($query) => $query->where('reseller_id', $reseller->id),
        )
            ->with([
                'layananInternet.pelanggan',
                'layananInternet.paketInternet',
                'pembayaran',
            ])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $tagihan]);
    }

    /**
     * Shadow login — buat kode penukaran sekali pakai untuk membuka portal
     * reseller di tab baru. Token asli tidak pernah lewat URL/share log:
     * admin yang menargetkan lalu POST ke /reseller/shadow/klaim.
     * Hanya boleh dipanggil oleh admin operasional/super_admin.
     */
    public function shadow(Admin $reseller)
    {
        if ($reseller->peran !== PeranAdminEnum::RESELLER || ! $reseller->status_aktif) {
            abort(404);
        }

        $kode = bin2hex(random_bytes(32));

        $sesi = \App\Models\ShadowSesi::create([
            'admin_id' => auth()->id(),
            'reseller_id' => $reseller->id,
            'kode_hash' => hash('sha256', $kode),
            'kode_kedaluwarsa_pada' => now()->addMinutes((int) env('SHADOW_KODE_EXPIRATION_MINUTES', 3)),
        ]);

        Log::info('Shadow login initiated', [
            'admin_id' => auth()->id(),
            'reseller_id' => $reseller->id,
            'shadow_sesi_id' => $sesi->id,
        ]);

        return response()->json([
            'data' => [
                'kode' => $kode,
                'reseller' => [
                    'id' => $reseller->id,
                    'nama_lengkap' => $reseller->nama_lengkap,
                    'peran' => $reseller->peran,
                    'foto_profil' => $reseller->foto_profil,
                ],
            ],
        ]);
    }

    /**
     * Batalkan semua shadow aktif admin ke reseller ini.
     */
    public function batalkanShadow(Admin $reseller)
    {
        if ($reseller->peran !== PeranAdminEnum::RESELLER) {
            abort(404);
        }

        $sesi = ShadowSesi::query()
            ->where('admin_id', auth()->id())
            ->where('reseller_id', $reseller->id)
            ->whereNull('diakhiri_pada')
            ->whereNotNull('token_id')
            ->get();

        foreach ($sesi as $s) {
            if ($s->token_id) {
                $s->reseller->tokens()->where('id', $s->token_id)->delete();
            }
            $s->update([
                'diakhiri_pada' => now(),
                'diakhiri_oleh' => auth()->id(),
            ]);
        }

        return response()->json([
            'message' => $sesi->count() > 0
                ? 'Shadow dibatalkan.'
                : 'Tidak ada shadow aktif.',
        ]);
    }

    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /** Monitoring global semua reseller: ringkasan + chart + transaksi terbaru. */
    public function statistik()
    {
        $this->authorize('viewAny', Admin::class);

        $resellers = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap', 'status_aktif']);
        $idList = $resellers->pluck('id');

        $jumlahPelanggan = $this->kelompokPelanggan($idList);
        $omzet = $this->kelompokOmzet($idList);

        $stats = [
            'total_reseller' => $resellers->count(),
            'reseller_aktif' => $resellers->where('status_aktif', true)->count(),
            'total_pelanggan' => (int) $jumlahPelanggan->sum(),
            'total_paket' => PaketInternet::whereIn('reseller_id', $idList)->count(),
            'total_tagihan' => $this->queryTagihan($idList)->count(),
            'total_pendapatan' => (float) array_sum($omzet->values()->all()),
        ];

        $distribusiPelanggan = $resellers->map(fn (Admin $r) => [
            'label' => $r->nama_lengkap,
            'jumlah' => (int) ($jumlahPelanggan[$r->id] ?? 0),
        ])->filter(fn ($d) => $d['jumlah'] > 0)->values();

        $omzetPerReseller = $resellers->map(fn (Admin $r) => [
            'label' => $r->nama_lengkap,
            'jumlah' => (float) ($omzet[$r->id] ?? 0),
        ])->filter(fn ($d) => $d['jumlah'] > 0)->values();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'distribusi_pelanggan' => $distribusiPelanggan,
                'omzet_per_reseller' => $omzetPerReseller,
                'transaksi_terbaru' => $this->transaksiTerbaru($idList),
            ],
        ]);
    }

    /** Monitoring per reseller: stats + trend pendapatan + distribusi paket/status + transaksi. */
    public function statistikReseller(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        if ($reseller->peran !== PeranAdminEnum::RESELLER) {
            abort(404);
        }

        $id = $reseller->id;
        $pendapatan = $this->queryAlokasiPembayaran([$id])->sum('pembayaran_tagihan.jumlah_dialokasikan');

        $distribusiStatus = $this->queryTagihan([$id])
            ->selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->filter(fn ($item) => $item->status_pembayaran)
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $this->labelStatus($item->status_pembayaran),
                'jumlah' => (int) $item->jumlah,
            ])->values();

        $stats = [
            'total_pelanggan' => Pelanggan::where('reseller_id', $id)->count(),
            'pelanggan_aktif' => Pelanggan::where('reseller_id', $id)
                ->whereHas('layananInternet', fn (Builder $q) => $q->where('status', StatusLayananEnum::AKTIF))
                ->count(),
            'total_paket' => PaketInternet::where('reseller_id', $id)->count(),
            'tagihan_dibuat' => $this->queryTagihan([$id])->count(),
            'tagihan_belum_bayar' => $this->queryTagihan([$id])
                ->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
                ->count(),
            'total_pendapatan' => (float) $pendapatan,
        ];

        return response()->json([
            'data' => [
                'stats' => $stats,
                'trend_pendapatan' => $this->trendPendapatan([$id]),
                'distribusi_status_tagihan' => $distribusiStatus,
                'distribusi_paket' => $this->distribusiPaket($id),
                'pelanggan_terbaru' => $reseller->pelanggan()->latest()->limit(5)->get(['id', 'nama_lengkap', 'nomor_pelanggan']),
                'transaksi_terbaru' => $this->transaksiTerbaru([$id]),
            ],
        ]);
    }

    /**
     * Daftar seluruh transaksi (tagihan terbit + pembayaran) milik reseller,
     * dengan filter reseller dan periode. Lanjutan dari card "Transaksi
     * Terbaru" di halaman monitoring reseller.
     */
    public function transaksi(DaftarTransaksiResellerRequest $request)
    {
        $this->authorize('viewAny', Admin::class);

        $idList = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->when($request->input('reseller_id'), fn (Builder $q, $id) => $q->where('id', $id))
            ->pluck('id');

        return response()->json([
            'data' => $this->paginateTransaksi(
                $idList,
                $request->integer('tahun') ?: null,
                $request->integer('bulan') ?: null,
                max(1, $request->integer('per_page', 20)),
                $request->integer('page', 1),
                $request,
            ),
        ]);
    }

    /** Laporan monitoring reseller PDF. */
    public function laporan(LaporanResellerRequest $request)
    {
        [$rekap, $transaksi, $grandTotal] = $this->susunLaporan($request);

        $pdf = Pdf::loadView('pdf.laporan-monitoring-reseller', [
            'rekap' => $rekap,
            'transaksi' => $transaksi,
            'grandTotal' => $grandTotal,
            'labelFilter' => $this->labelFilter($request),
            'labelPeriode' => $this->labelPeriode($request),
        ]);

        $slug = str("laporan-reseller-{$this->slugFilter($request)}")->slug()->toString();

        return $pdf->stream("{$slug}.pdf");
    }

    /** Laporan monitoring reseller Excel (2 sheet: ringkasan + transaksi). */
    public function laporanExcel(LaporanResellerRequest $request)
    {
        [$rekap, $transaksi] = $this->susunLaporan($request);

        $file = Excel::raw(new ResellerLaporanExport(
            $rekap,
            $transaksi,
            $this->labelFilter($request),
            $this->labelPeriode($request),
        ), \Maatwebsite\Excel\Excel::XLSX);

        $slug = str("laporan-reseller-{$this->slugFilter($request)}")->slug()->toString();

        return response($file, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$slug}.xlsx\"",
        ]);
    }

    // ─── Bantuan monitoring ─────────────────────────────────────────

    /** Tagihan milik pelanggan dari reseller tertentu (null = semua reseller). */
    private function queryTagihan(iterable $idList): Builder
    {
        return Tagihan::whereHas(
            'layananInternet.pelanggan',
            fn (Builder $q) => $q->whereIn('reseller_id', is_array($idList) ? $idList : $idList->all())->whereNotNull('reseller_id'),
        );
    }

    /**
     * Pembayaran BERHASIL milik pelanggan reseller.
     */
    private function queryPembayaran(iterable $idList): Builder
    {
        $ids = is_array($idList) ? $idList : $idList->all();

        return Pembayaran::query()
            ->where('pembayaran.status', StatusTransaksiEnum::BERHASIL)
            ->whereHas('pelanggan', function (Builder $q) use ($ids) {
                $q->whereIn('reseller_id', $ids);
            });
    }

    /**
     * Alokasi pembayaran BERHASIL ke tagihan milik reseller.
     *
     * Sumber omzet reseller adalah jumlah yang benar-benar dialokasikan
     * ke tagihan reseller, bukan total pembayaran customer.
     */
    private function queryAlokasiPembayaran(iterable $idList): Builder
    {
        $ids = is_array($idList) ? $idList : $idList->all();

        return PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran_tagihan.pembayaran_id', '=', 'pembayaran.id')
            ->join('tagihan', 'pembayaran_tagihan.tagihan_id', '=', 'tagihan.id')
            ->join('layanan_internet', 'tagihan.layanan_internet_id', '=', 'layanan_internet.id')
            ->join('pelanggan', 'layanan_internet.pelanggan_id', '=', 'pelanggan.id')
            ->where('pembayaran.status', StatusTransaksiEnum::BERHASIL)
            ->whereIn('pelanggan.reseller_id', $ids)
            ->whereNotNull('pembayaran.dibayar_pada');
    }

    private function kelompokPelanggan(iterable $idList): \Illuminate\Support\Collection
    {
        return Pelanggan::whereIn('reseller_id', is_array($idList) ? $idList : $idList->all())
            ->selectRaw('reseller_id, count(*) as jumlah')
            ->groupBy('reseller_id')
            ->pluck('jumlah', 'reseller_id');
    }

    private function kelompokOmzet(iterable $idList): \Illuminate\Support\Collection
    {
        return $this->queryAlokasiPembayaran($idList)
            ->selectRaw('pelanggan.reseller_id, SUM(pembayaran_tagihan.jumlah_dialokasikan) as total')
            ->groupBy('pelanggan.reseller_id')
            ->pluck('total', 'pelanggan.reseller_id')
            ->map(fn ($total) => (float) $total);
    }

    /** Trend pendapatan 12 bulan terakhir [{bulan, jumlah}]. */
    private function trendPendapatan(iterable $idList): array
    {
        $mulai = Carbon::now()->startOfMonth()->subMonths(11);

        $rows = $this->queryAlokasiPembayaran($idList)
            ->where('pembayaran.dibayar_pada', '>=', $mulai)
            ->selectRaw('date(pembayaran.dibayar_pada) as tanggal, SUM(pembayaran_tagihan.jumlah_dialokasikan) as total')
            ->groupBy('tanggal')
            ->get();

        $perBulan = [];

        foreach ($rows as $row) {
            $kode = Carbon::parse($row->tanggal)->format('Y-m');
            $perBulan[$kode] = ($perBulan[$kode] ?? 0) + (float) $row->total;
        }

        $trend = [];

        for ($i = 11; $i >= 0; $i--) {
            $tgl = $mulai->copy()->addMonths($i);
            $kode = $tgl->format('Y-m');

            $trend[] = [
                'bulan' => self::NAMA_BULAN[(int) $tgl->format('n')].' '.substr((string) $tgl->year, 2),
                'jumlah' => (float) ($perBulan[$kode] ?? 0),
            ];
        }

        return $trend;
    }

    /** Distribusi paket yang dipakai pelanggan aktif reseller [{label, jumlah}]. */
    private function distribusiPaket(int $id): array
    {
        $rows = Pelanggan::where('reseller_id', $id)
            ->with(['layananInternet' => fn ($q) => $q->where('status', StatusLayananEnum::AKTIF)->with('paketInternet')])
            ->get()
            ->flatMap(fn (Pelanggan $p) => $p->layananInternet)
            ->map(function ($layanan) {
                if ($layanan->tipe_paket === 'custom' && filled($layanan->nama_paket_custom)) {
                    return $layanan->nama_paket_custom;
                }

                return $layanan->paketInternet?->nama_paket ?? 'Tanpa Paket';
            })
            ->countBy()
            ->map(fn ($jumlah, $nama) => ['label' => (string) $nama, 'jumlah' => $jumlah])
            ->values()
            ->all();

        return $rows;
    }

    /** Gabungan tagihan dibuat + pembayaran masuk, terurut terbaru. */
    private function transaksiTerbaru(iterable $idList, int $limit = 8): array
    {
        return $this->gabungTransaksi(
            $this->queryTagihan($idList)
                ->with('layananInternet.pelanggan.reseller')
                ->latest('created_at')
                ->limit($limit)
                ->get(),
            $this->queryPembayaran($idList)
                ->with('pelanggan.reseller', 'alokasiTagihan.tagihan')
                ->latest('dibayar_pada')
                ->limit($limit)
                ->get(),
            $limit,
        );
    }

    /**
     * Satu baris transaksi untuk tagihan yang baru diterbitkan.
     */
    private function barisTagihan(Tagihan $t): array
    {
        return [
            'id' => $t->id,
            'jenis' => 'tagihan',
            'nomor' => $t->nomor_tagihan,
            'reseller' => $t->layananInternet?->pelanggan?->reseller?->nama_lengkap ?? '-',
            'pelanggan' => $t->layananInternet?->pelanggan?->nama_lengkap ?? '-',
            'nominal' => (float) $t->total_tagihan,
            'status' => $this->labelStatus($t->status_pembayaran),
            'waktu' => $t->created_at?->format('d M Y H:i'),
        ];
    }

    /**
     * Satu baris transaksi untuk pembayaran yang berhasil.
     */
    private function barisPembayaran(Pembayaran $p): array
    {
        return [
            'id' => $p->id,
            'jenis' => 'pembayaran',
            'nomor' => $p->alokasiTagihan
                ->pluck('tagihan.nomor_tagihan')
                ->filter()
                ->join(', ') ?: '#'.$p->id,
            'reseller' => $p->pelanggan?->reseller?->nama_lengkap ?? '-',
            'pelanggan' => $p->pelanggan?->nama_lengkap ?? '-',
            'nominal' => (float) $p->jumlah_dibayar,
            'status' => 'Lunas',
            'waktu' => $p->dibayar_pada?->format('d M Y H:i'),
        ];
    }

    /**
     * Gabung dua sumber transaksi lalu urutkan benar berdasarkan waktu aktual.
     *
     * Pengurutan memakai timestamp asli, bukan teks "d M Y H:i" — sorting
     * berdasarkan string salah urutan begitu tanggal dan bulannya berbeda.
     */
    private function gabungTransaksi(Collection $tagihan, Collection $pembayaran, int $limit): array
    {
        return $tagihan->map(fn (Tagihan $t) => [
                'urut' => $t->created_at?->getTimestamp() ?? 0,
                'baris' => $this->barisTagihan($t),
            ])
            ->concat($pembayaran->map(fn (Pembayaran $p) => [
                'urut' => $p->dibayar_pada?->getTimestamp() ?? 0,
                'baris' => $this->barisPembayaran($p),
            ]))
            ->filter(fn (array $item) => filled($item['baris']['waktu']))
            ->sortByDesc('urut')
            ->take($limit)
            ->pluck('baris')
            ->values()
            ->all();
    }

    /**
     * Daftar transaksi terpaginasi.
     *
     * Tagihan dan pembayaran ada di dua tabel berbeda, jadi gabungannya tidak
     * bisa dipaginasi langsung lewat `paginate()`. Halaman ini disusun dari
     * indeks ringan (jenis + id + waktu), lalu hanya baris pada halaman itu
     * yang dimuat lengkap — `total` tetap akurat walau datanya banyak.
     */
    private function paginateTransaksi(iterable $idList, ?int $tahun, ?int $bulan, int $perPage, int $page, Request $request): LengthAwarePaginator
    {
        $indeks = $this->indeksTransaksi($idList, $tahun, $bulan);
        $total = $indeks->count();
        $slice = $indeks->slice(($page - 1) * $perPage, $perPage)->values();

        $barisTagihan = Tagihan::with('layananInternet.pelanggan.reseller')
            ->whereIn('id', $slice->where('jenis', 'tagihan')->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Tagihan $t) => [$t->id => $this->barisTagihan($t)]);

        $barisPembayaran = Pembayaran::with('pelanggan.reseller', 'alokasiTagihan.tagihan')
            ->whereIn('id', $slice->where('jenis', 'pembayaran')->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Pembayaran $p) => [$p->id => $this->barisPembayaran($p)]);

        $data = $slice
            ->map(fn (array $s) => $s['jenis'] === 'tagihan'
                ? $barisTagihan->get($s['id'])
                : $barisPembayaran->get($s['id']))
            ->filter()
            ->values()
            ->all();

        return new LengthAwarePaginator($data, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
    }

    /** Indeks ringan transaksi: [{jenis, id, urut}] terurut waktu terbaru. */
    private function indeksTransaksi(iterable $idList, ?int $tahun, ?int $bulan): Collection
    {
        $qTagihan = $this->queryTagihan($idList);
        $qPembayaran = $this->queryPembayaran($idList);

        if ($tahun !== null) {
            $qTagihan->whereYear('created_at', $tahun);
            $qPembayaran->whereYear('dibayar_pada', $tahun);
        }

        if ($bulan !== null) {
            $qTagihan->whereMonth('created_at', $bulan);
            $qPembayaran->whereMonth('dibayar_pada', $bulan);
        }

        $tagihan = $qTagihan->get(['id', 'created_at'])
            ->map(fn (Tagihan $t) => [
                'jenis' => 'tagihan',
                'id' => $t->id,
                'urut' => $t->created_at?->getTimestamp() ?? 0,
            ]);

        $pembayaran = $qPembayaran->whereNotNull('dibayar_pada')->get(['id', 'dibayar_pada'])
            ->map(fn (Pembayaran $p) => [
                'jenis' => 'pembayaran',
                'id' => $p->id,
                'urut' => $p->dibayar_pada?->getTimestamp() ?? 0,
            ]);

        return $tagihan->concat($pembayaran)
            ->filter(fn (array $item) => $item['urut'] > 0)
            ->sortByDesc('urut')
            ->values();
    }

    /** Data laporan PDF/Excel: ringkasan per reseller + detail transaksi. */
    private function susunLaporan(LaporanResellerRequest $request): array
    {
        $scope = $request->input('reseller_id');
        $tahun = (int) $request->input('tahun', now()->year);
        $bulan = $request->integer('bulan');

        $resellers = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->when($scope, fn ($q) => $q->where('id', $scope))
            ->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap', 'email', 'status_aktif', 'created_at']);
        $idList = $resellers->pluck('id');

        $jumlahPelanggan = $this->kelompokPelanggan($idList);
        $omzet = $this->kelompokOmzet($idList);

        $rekap = $resellers->map(function (Admin $r) use ($jumlahPelanggan, $omzet, $tahun, $bulan) {
            $queryPelanggan = Pelanggan::where('reseller_id', $r->id);
            $queryTagihan = $this->queryTagihan([$r->id])->when($tahun, fn ($q) => $q->whereYear('created_at', $tahun));
            if ($bulan !== null) {
                $queryTagihan->whereMonth('created_at', $bulan);
            }
            $queryBayar = $this->queryAlokasiPembayaran([$r->id])->when($tahun, fn ($q) => $q->whereYear('pembayaran.dibayar_pada', $tahun));
            if ($bulan !== null) {
                $queryBayar->whereMonth('pembayaran.dibayar_pada', $bulan);
            }

            return [
                'id' => $r->id,
                'nama' => $r->nama_lengkap,
                'email' => $r->email,
                'status' => $r->status_aktif ? 'Aktif' : 'Nonaktif',
                'terdaftar' => $r->created_at?->format('d M Y'),
                'pelanggan' => $queryPelanggan->count(),
                'pelanggan_aktif' => (clone $queryPelanggan)
                    ->whereHas('layananInternet', fn (Builder $q) => $q->where('status', StatusLayananEnum::AKTIF))
                    ->count(),
                'paket' => PaketInternet::where('reseller_id', $r->id)->count(),
                'tagihan_dibuat' => (clone $queryTagihan)->count(),
                'tagihan_lunas' => (clone $queryTagihan)->where('status_pembayaran', StatusPembayaranEnum::SUDAH_BAYAR)->count(),
                'tagihan_belum_bayar' => (clone $queryTagihan)->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)->count(),
                'pendapatan' => (float) (clone $queryBayar)->sum('pembayaran_tagihan.jumlah_dialokasikan'),
            ];
        })->values()->all();

        $transaksi = $this->transaksiTerbaruKunciLengkap($idList, $tahun, $bulan);

        $grandTotal = array_sum(array_column($rekap, 'pendapatan'));

        return [$rekap, $transaksi, (float) $grandTotal];
    }

    private function transaksiTerbaruKunciLengkap(iterable $idList, int $tahun, ?int $bulan): array
    {
        $limit = 300;

        $qTagihan = $this->queryTagihan($idList)->with('layananInternet.pelanggan.reseller')->whereYear('created_at', $tahun);
        $qBayar = $this->queryPembayaran($idList)
            ->with('pelanggan.reseller', 'alokasiTagihan.tagihan')
            ->whereYear('dibayar_pada', $tahun);

        if ($bulan !== null) {
            $qTagihan->whereMonth('created_at', $bulan);
            $qBayar->whereMonth('dibayar_pada', $bulan);
        }

        return $this->gabungTransaksi(
            $qTagihan->latest('created_at')->limit($limit)->get(),
            $qBayar->latest('dibayar_pada')->limit($limit)->get(),
            $limit * 2,
        );
    }

    private function labelStatus(StatusPembayaranEnum $status): string
    {
        return match ($status) {
            StatusPembayaranEnum::BELUM_BAYAR => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR => 'Lunas',
            default => '-',
        };
    }

    private function labelFilter(LaporanResellerRequest $request): string
    {
        $scope = $request->input('reseller_id');
        if (! $scope) {
            return 'Semua Reseller';
        }

        $reseller = Admin::where('peran', PeranAdminEnum::RESELLER)->find($scope);

        return $reseller ? $reseller->nama_lengkap : 'Reseller Tidak Dikenal';
    }

    private function slugFilter(LaporanResellerRequest $request): string
    {
        $scope = $request->input('reseller_id');

        return $scope ? "reseller-{$scope}" : 'semua-reseller';
    }

    private function labelPeriode(LaporanResellerRequest $request): string
    {
        $tahun = (int) $request->input('tahun', now()->year);
        $bulan = $request->integer('bulan');

        if ($bulan !== null) {
            $nama = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'][$bulan] ?? "Bulan {$bulan}";

            return $nama." {$tahun}";
        }

        return "Tahun {$tahun}";
    }
}