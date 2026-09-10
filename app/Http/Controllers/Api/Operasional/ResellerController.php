<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Exports\ResellerLaporanExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\LaporanResellerRequest;
use App\Http\Requests\Operasional\SimpanResellerRequest;
use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Repositories\Contracts\AdminRepositoryInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
        $pendapatan = $this->queryPembayaran([$id])->sum('jumlah_dibayar');

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

    /** Pembayaran BERHASIL milik pelanggan reseller. */
    private function queryPembayaran(iterable $idList): Builder
    {
        return Pembayaran::where('pembayaran.status', StatusTransaksiEnum::BERHASIL)
            ->whereHas('tagihan.layananInternet.pelanggan', fn (Builder $q) => $q->whereIn('reseller_id', is_array($idList) ? $idList : $idList->all()));
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
        return $this->queryPembayaran($idList)
            ->selectRaw('pembayaran.id, tagihan.layanan_internet_id, layanan_internet.pelanggan_id, pelanggan.reseller_id, pembayaran.jumlah_dibayar')
            ->join('tagihan', 'pembayaran.tagihan_id', '=', 'tagihan.id')
            ->join('layanan_internet', 'tagihan.layanan_internet_id', '=', 'layanan_internet.id')
            ->join('pelanggan', 'layanan_internet.pelanggan_id', '=', 'pelanggan.id')
            ->get()
            ->groupBy('reseller_id')
            ->map(fn ($grup) => (float) $grup->sum(fn ($row) => (float) $row->jumlah_dibayar));
    }

    /** Trend pendapatan 12 bulan terakhir [{bulan, jumlah}]. */
    private function trendPendapatan(iterable $idList): array
    {
        $mulai = Carbon::now()->startOfMonth()->subMonths(11);
        $rows = $this->queryPembayaran($idList)
            ->where('dibayar_pada', '>=', $mulai)
            ->selectRaw('date(dibayar_pada) as tanggal, SUM(jumlah_dibayar) as total')
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
        $tagihan = $this->queryTagihan($idList)
            ->with('layananInternet.pelanggan.reseller')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Tagihan $t) => [
                'id' => $t->id,
                'jenis' => 'tagihan',
                'nomor' => $t->nomor_tagihan,
                'reseller' => $t->layananInternet?->pelanggan?->reseller?->nama_lengkap ?? '-',
                'pelanggan' => $t->layananInternet?->pelanggan?->nama_lengkap ?? '-',
                'nominal' => (float) $t->total_tagihan,
                'status' => $this->labelStatus($t->status_pembayaran),
                'waktu' => $t->created_at?->format('d M Y H:i'),
            ]);

        $pembayaran = $this->queryPembayaran($idList)
            ->with('tagihan.layananInternet.pelanggan.reseller')
            ->latest('dibayar_pada')
            ->limit($limit)
            ->get()
            ->map(fn (Pembayaran $p) => [
                'id' => $p->id,
                'jenis' => 'pembayaran',
                'nomor' => $p->tagihan?->nomor_tagihan ?? '#'.$p->id,
                'reseller' => $p->tagihan?->layananInternet?->pelanggan?->reseller?->nama_lengkap ?? '-',
                'pelanggan' => $p->tagihan?->layananInternet?->pelanggan?->nama_lengkap ?? '-',
                'nominal' => (float) $p->jumlah_dibayar,
                'status' => 'Lunas',
                'waktu' => $p->dibayar_pada?->format('d M Y H:i'),
            ]);

        return $tagihan->concat($pembayaran)
            ->filter(fn ($t) => filled($t['waktu']))
            ->sortByDesc('waktu')
            ->take($limit)
            ->values()
            ->all();
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
            $queryBayar = $this->queryPembayaran([$r->id])->when($tahun, fn ($q) => $q->whereYear('dibayar_pada', $tahun));
            if ($bulan !== null) {
                $queryBayar->whereMonth('dibayar_pada', $bulan);
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
                'pendapatan' => (float) (clone $queryBayar)->sum('jumlah_dibayar'),
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
        $qBayar = $this->queryPembayaran($idList)->with('tagihan.layananInternet.pelanggan.reseller')->whereYear('dibayar_pada', $tahun);
        if ($bulan !== null) {
            $qTagihan->whereMonth('created_at', $bulan);
            $qBayar->whereMonth('dibayar_pada', $bulan);
        }

        $tagihan = $qTagihan->latest('created_at')->limit($limit)->get()->map(fn (Tagihan $t) => [
            'jenis' => 'tagihan',
            'nomor' => $t->nomor_tagihan,
            'reseller' => $t->layananInternet?->pelanggan?->reseller?->nama_lengkap ?? '-',
            'pelanggan' => $t->layananInternet?->pelanggan?->nama_lengkap ?? '-',
            'nominal' => (float) $t->total_tagihan,
            'status' => $this->labelStatus($t->status_pembayaran),
            'waktu' => $t->created_at?->format('d M Y H:i'),
        ]);

        $bayar = $qBayar->latest('dibayar_pada')->limit($limit)->get()->map(fn (Pembayaran $p) => [
            'jenis' => 'pembayaran',
            'nomor' => $p->tagihan?->nomor_tagihan ?? '#'.$p->id,
            'reseller' => $p->tagihan?->layananInternet?->pelanggan?->reseller?->nama_lengkap ?? '-',
            'pelanggan' => $p->tagihan?->layananInternet?->pelanggan?->nama_lengkap ?? '-',
            'nominal' => (float) $p->jumlah_dibayar,
            'status' => 'Lunas',
            'waktu' => $p->dibayar_pada?->format('d M Y H:i'),
        ]);

        return $tagihan->concat($bayar)->sortByDesc('waktu')->values()->all();
    }

    private function labelStatus(StatusPembayaranEnum $status): string
    {
        return match ($status) {
            StatusPembayaranEnum::BELUM_BAYAR => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR => 'Lunas',
            StatusPembayaranEnum::KEDALUWARSA => 'Kedaluwarsa',
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