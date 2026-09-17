<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Exports\LaporanPendapatanMultiSheetExport;
use App\Http\Controllers\Controller;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class PendapatanController extends Controller
{
    public function __construct(
        private PembayaranAllocationService $pembayaranAllocationService,
    ) {
    }

    /** Scope reseller (null = admin keuangan melihat semua). */
    protected ?int $resellerId = null;

    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    private const NAMA_BULAN_LENGKAP = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** Daftar pelanggan untuk dropdown multi-select (dengan provinsi/kota untuk filter realtime). */
    public function pelangganList()
    {
        $data = Pelanggan::query()
            ->select('id', 'nama_lengkap', 'nomor_pelanggan')
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereNull('reseller_id'),
                fn ($q) => $q->where('reseller_id', $this->resellerId)
            )
            ->with(['layananInternet' => fn ($q) => $q->select('id', 'pelanggan_id', 'provinsi', 'kota')])
            ->orderBy('nama_lengkap')
            ->get()
            ->map(function (Pelanggan $p) {
                $layanan = $p->layananInternet->first();

                return [
                    'id' => $p->id,
                    'nama_lengkap' => $p->nama_lengkap,
                    'nomor_pelanggan' => $p->nomor_pelanggan,
                    'provinsi' => $layanan?->provinsi,
                    'kota' => $layanan?->kota,
                ];
            });

        return response()->json(['data' => $data]);
    }

    /** Ringkasan pendapatan dengan filter tahun, bulan[], pelanggan_ids[]. */
    public function index(Request $request)
    {
        $query = $this->pembayaranQuery($request);

        $stats = [
            'total_pendapatan' => $this->rupiah($query->sum('pembayaran_tagihan.jumlah_dialokasikan')),
            'jumlah_pembayaran' => (clone $query)->distinct('pembayaran.id')->count('pembayaran.id'),
            'tagihan_dibuat' => $this->tagihanQuery($request)->count(),
        ];

        $distribusiPembayaran = $this->tagihanQuery($request)
            ->selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $this->labelStatus($item->status_pembayaran),
                'jumlah' => (int) $item->jumlah,
            ]);

        $tren = $this->hitungTren(clone $query, $request);

        $pembayaranIds = (clone $query)
            ->select('pembayaran.id')
            ->distinct();

        $pembayaranTerbaru = Pembayaran::query()
            ->whereIn('id', $pembayaranIds)
            ->with([
                'pelanggan',
                'alokasiTagihan.tagihan',
            ])
            ->latest('dibayar_pada')
            ->take(10)
            ->get()
            ->map(fn (Pembayaran $pembayaran) => [
                'id' => $pembayaran->id,
                'nomor_tagihan' => $pembayaran->alokasiTagihan
                    ->pluck('tagihan.nomor_tagihan')
                    ->filter()
                    ->join(', '),
                'pelanggan' => $pembayaran->pelanggan?->nama_lengkap,
                'jumlah' => $this->rupiah($pembayaran->jumlah_dibayar),
                'status' => $pembayaran->status->value,
                'waktu' => $pembayaran->dibayar_pada?->format('d M Y H:i'),
            ]);

        return response()->json([
            'data' => [
                'filter' => $this->filterMeta($request),
                'stats' => $stats,
                'tren' => $tren,
                'distribusi_pembayaran' => $distribusiPembayaran,
                'pembayaran_terbaru' => $pembayaranTerbaru,
            ],
        ]);
    }

    /** Laporan pendapatan PDF (A4 portrait, multi-bagian). */
    public function report(Request $request)
    {
        $ringkasan = $this->buildRingkasanData($request);
        $transaksi = $this->buildTransaksiData($request);
        $alokasi = $this->buildAlokasiData($request);
        $saldoKredit = $this->buildSaldoKreditData($request);

        $ringkasanTotal = [
            'total_tagihan' => array_sum(array_column($ringkasan, 'total_tagihan')),
            'pembayaran_masuk' => array_sum(array_column($transaksi, 'jumlah_dibayar')),
            'dialokasikan' => array_sum(array_column($alokasi, 'jumlah_dialokasikan')),
            'kredit_masuk' => array_sum(array_column(
                array_filter($saldoKredit, fn ($m) => $m['jenis'] === 'Kredit'),
                'jumlah'
            )),
            'kredit_digunakan' => array_sum(array_column(
                array_filter($saldoKredit, fn ($m) => $m['jenis'] === 'Pemakaian'),
                'jumlah'
            )),
            'tagihan_lunas' => count(array_filter($ringkasan, fn ($r) => $r['status'] === 'Lunas')),
            'tagihan_belum_lunas' => count(array_filter($ringkasan, fn ($r) => $r['status'] !== 'Lunas')),
        ];

        $pdf = Pdf::loadView('pdf.report-pendapatan', [
            'labelPeriode' => $this->labelPeriode($request),
            'generatedAt' => Carbon::now()->timezone('Asia/Jakarta')->format('d M Y, H:i'),
            'ringkasan' => $ringkasan,
            'transaksi' => $transaksi,
            'alokasi' => $alokasi,
            'saldoKredit' => $saldoKredit,
            'ringkasanTotal' => $ringkasanTotal,
        ])->setPaper('a4', 'portrait');

        $slug = str($this->labelPeriode($request))->slug()->toString();

        return $pdf->stream("laporan-pendapatan-{$slug}.pdf");
    }

    /** Laporan pendapatan Excel (multi-sheet). */
    public function reportExcel(Request $request)
    {
        $file = Excel::raw(new LaporanPendapatanMultiSheetExport(
            $this->buildRingkasanData($request),
            $this->buildTransaksiData($request),
            $this->buildAlokasiData($request),
            $this->buildSaldoKreditData($request),
            $this->labelPeriode($request),
        ), \Maatwebsite\Excel\Excel::XLSX);

        $slug = str($this->labelPeriode($request))->slug()->toString();

        return response($file, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"laporan-pendapatan-{$slug}.xlsx\"",
        ]);
    }

    // ─── Matrix Builder ─────────────────────────────────────────────

    private function buildMatrix(Request $request): array
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulanList = $this->parseBulanArray($request) ?? range(1, 12);
        sort($bulanList);

        // 1. Ambil pelanggan
        $pelangganQuery = Pelanggan::query()
            ->select('id', 'nama_lengkap', 'nomor_pelanggan')
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereNull('reseller_id'),
                fn ($q) => $q->where('reseller_id', $this->resellerId)
            );
        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $pelangganQuery->whereIn('id', array_map('intval', $pelangganIds));
        }
        $pelangganList = $pelangganQuery->orderBy('nama_lengkap')->get();

        // 2. Ambil semua layanan internet aktif beserta tanggal_aktif
        $layananQuery = LayananInternet::query()
            ->select('id', 'pelanggan_id', 'tanggal_aktif', 'status')
            ->where('status', 'aktif')
            ->whereHas('pelanggan', function (Builder $q) {
                if ($this->resellerId === null) {
                    $q->whereNull('reseller_id');
                } else {
                    $q->where('reseller_id', $this->resellerId);
                }
            });
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $layananQuery->whereIn('pelanggan_id', array_map('intval', $pelangganIds));
        }
        $layananAktif = $layananQuery->get()->keyBy('id');

        // 3. Ambil tagihan untuk tahun + bulan yang diminta
        $tagihanQuery = Tagihan::query()
            ->select(
                'id',
                'layanan_internet_id',
                'periode_bulan',
                'periode_tahun',
                'status_pembayaran',
                'total_tagihan'
            )
            ->where('periode_tahun', $tahun)
            ->whereIn('periode_bulan', $bulanList);

        $tagihanQuery->whereHas('layananInternet.pelanggan', function (Builder $q) {
            if ($this->resellerId === null) {
                $q->whereNull('reseller_id');
            } else {
                $q->where('reseller_id', $this->resellerId);
            }
        });
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $tagihanQuery->whereHas('layananInternet', function (Builder $q) use ($pelangganIds) {
                $q->whereIn('pelanggan_id', array_map('intval', $pelangganIds));
            });
        }
        $semuaTagihan = $tagihanQuery->get();

        // Index tagihan: layanan_internet_id -> bulan -> tagihan
        $tagihanIndex = [];
        foreach ($semuaTagihan as $t) {
            $tagihanIndex[$t->layanan_internet_id][$t->periode_bulan] = $t;
        }

        // 4. Ambil pembayaran BERHASIL untuk tagihan yang sudah dibayar
        $tagihanIds = $semuaTagihan->pluck('id')->values();
        $pembayaranMap = [];
        if ($tagihanIds->isNotEmpty()) {
            $pembayaranBerhasil = PembayaranTagihan::query()
                ->with('pembayaran')
                ->whereHas('pembayaran', function (Builder $q) {
                    $q->where('status', StatusTransaksiEnum::BERHASIL);
                })
                ->whereIn('tagihan_id', $tagihanIds)
                ->get()
                ->groupBy('tagihan_id');

            foreach ($pembayaranBerhasil as $tagihanId => $alokasi) {
                $totalBayar = $alokasi->sum('jumlah_dialokasikan');

                $tanggalBayar = $alokasi
                    ->map(fn (PembayaranTagihan $item) => $item->pembayaran?->dibayar_pada)
                    ->filter()
                    ->max();

                $pembayaranMap[$tagihanId] = [
                    'nominal' => (float) $totalBayar,
                    'tanggal' => $tanggalBayar instanceof Carbon
                        ? $tanggalBayar->format('d-m-Y H:i:s')
                        : '',
                ];
            }
        }

        // 5. Bangun matriks: pelanggan × bulan
        $baris = [];
        $total = 0;

        foreach ($pelangganList as $plg) {
            $nama = $plg->nama_lengkap;
            $nomor = $plg->nomor_pelanggan;
            $row = ['nama' => $nama, 'nomor' => $nomor, 'cells' => []];

            // Cari layanan aktif pelanggan ini
            $layananPlg = $layananQuery->clone()
                ->where('pelanggan_id', $plg->id)
                ->get();

            foreach ($bulanList as $bulan) {
                $akhirBulan = Carbon::createFromDate($tahun, $bulan, 1)->endOfMonth();

                // Cek apakah pelanggan sudah berlangganan di bulan ini
                $aktifDiBulan = $layananPlg->contains(function (LayananInternet $l) use ($akhirBulan) {
                    return $l->tanggal_aktif && $l->tanggal_aktif->lte($akhirBulan);
                });

                if (! $aktifDiBulan) {
                    $row['cells'][] = ['status' => 'belum_berlangganan', 'label' => 'Belum Berlangganan'];

                    continue;
                }

                // Cari tagihan untuk bulan ini
                $tagihanDitemukan = null;
                foreach ($layananPlg as $l) {
                    if (isset($tagihanIndex[$l->id][$bulan])) {
                        $tagihanDitemukan = $tagihanIndex[$l->id][$bulan];
                        break;
                    }
                }

                if (! $tagihanDitemukan) {
                    $row['cells'][] = ['status' => 'belum_berlangganan', 'label' => 'Belum Berlangganan'];

                    continue;
                }

                if ($tagihanDitemukan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
                    $bayar = $pembayaranMap[$tagihanDitemukan->id] ?? null;
                    $row['cells'][] = [
                        'status' => 'lunas',
                        'nominal' => $this->rupiah($bayar['nominal'] ?? $tagihanDitemukan->total_tagihan),
                        'tanggal' => $bayar['tanggal'] ?? '',
                        'nominalRaw' => (float) ($bayar['nominal'] ?? $tagihanDitemukan->total_tagihan),
                    ];
                    $total += (float) ($bayar['nominal'] ?? $tagihanDitemukan->total_tagihan);
                } else {
                    $row['cells'][] = ['status' => 'belum_bayar', 'label' => 'Belum Bayar / Nunggak'];
                }
            }

            $baris[] = $row;
        }

        return [
            'data' => $baris,
            'kolomBulan' => $bulanList,
            'total' => $total,
        ];
    }

    // ─── Multi-Sheet Builders ──────────────────────────────────────

    /**
     * Ringkasan timeline pelanggan × bulan.
     *
     * Setiap pelanggan selalu memiliki satu baris per bulan dalam rentang
     * laporan (Januari s/d bulan maksimum filter, atau Desember bila "semua
     * bulan"). Status per bulan diturunkan dari data tagihan & pembayaran;
     * nominal tetap numerik agar dapat di-SUM di Excel.
     *
     * Periode tagihan memakai periode AWAL tagihan (periode_bulan/tahun).
     * Tagihan multi-bulan (jumlah_bulan > 1) tidak dipecah ke bulan-bulan
     * yang dicakupnya — dipetakan penuh ke periode awalnya, konsisten dengan
     * sheet alokasi & perhitungan sisa di PembayaranAllocationService.
     * ponytail: atribusi multi-bulan ke period awal; pecah/dobel berlaku bila
     * timeline harus menampilkan cakupan per bulan.
     */
    private function buildRingkasanData(Request $request): array
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulanTerpilih = $this->parseBulanArray($request);
        $bulanAkhir = $bulanTerpilih !== null ? max($bulanTerpilih) : 12;

        $pelangganList = Pelanggan::query()
            ->select('id', 'nama_lengkap', 'nomor_pelanggan')
            ->with('layananInternet:id,pelanggan_id,status,tanggal_aktif,tanggal_mulai_penagihan')
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereNull('reseller_id'),
                fn ($q) => $q->where('reseller_id', $this->resellerId)
            )
            ->orderBy('nama_lengkap')
            ->get();

        if ($pelangganList->isEmpty()) {
            return [];
        }

        $pelangganIds = $pelangganList->pluck('id');
        $requestPelanggan = $request->input('pelanggan_ids');
        if (is_array($requestPelanggan) && count($requestPelanggan) > 0) {
            $pelangganIds = $pelangganIds->intersect(array_map('intval', $requestPelanggan))->values();
        }

        $layananKePelanggan = [];
        foreach ($pelangganList as $plg) {
            foreach ($plg->layananInternet as $l) {
                $layananKePelanggan[$l->id] = $plg->id;
            }
        }

        // Semua tagihan pelanggan dalam scope (tidak dibatasi tahun laporan,
        // karena sisa tagihan tahun sebelumnya menentukan status Nunggak).
        $tagihan = Tagihan::query()
            ->whereHas('layananInternet', fn (Builder $q) => $q->whereIn('pelanggan_id', $pelangganIds))
            ->with('alokasiPembayaran.pembayaran:id,status')
            ->get();

        // Kredit/deposit yang terpakai per tagihan (ledger pemakaian).
        $kreditTerpakai = MutasiSaldoKredit::query()
            ->selectRaw('tagihan_id, SUM(jumlah) as total')
            ->whereIn('tagihan_id', $tagihan->pluck('id'))
            ->where('jenis', 'pemakaian')
            ->groupBy('tagihan_id')
            ->pluck('total', 'tagihan_id');

        $bulananPerPelanggan = [];
        $sisaPerBulan = [];

        foreach ($tagihan as $t) {
            $plgId = $layananKePelanggan[$t->layanan_internet_id] ?? null;
            if ($plgId === null) {
                continue;
            }

            $dibayarPembayaran = (float) $t->alokasiPembayaran
                ->filter(fn (PembayaranTagihan $a) => $a->pembayaran?->status === StatusTransaksiEnum::BERHASIL)
                ->sum('jumlah_dialokasikan');
            $dibayarKredit = (float) ($kreditTerpakai[$t->id] ?? 0);
            $totalTagihan = (float) $t->total_tagihan;

            $key = ($t->periode_tahun * 12) + $t->periode_bulan;

            $bulananPerPelanggan[$plgId][$key]['nomor_tagihan'][] = $t->nomor_tagihan;
            $bulananPerPelanggan[$plgId][$key]['total_tagihan'] = ($bulananPerPelanggan[$plgId][$key]['total_tagihan'] ?? 0) + $totalTagihan;
            $bulananPerPelanggan[$plgId][$key]['dibayar_pembayaran'] = ($bulananPerPelanggan[$plgId][$key]['dibayar_pembayaran'] ?? 0) + $dibayarPembayaran;
            $bulananPerPelanggan[$plgId][$key]['dibayar_kredit'] = ($bulananPerPelanggan[$plgId][$key]['dibayar_kredit'] ?? 0) + $dibayarKredit;

            $tanggalLunasSebelum = $bulananPerPelanggan[$plgId][$key]['tanggal_lunas'] ?? null;
            if ($t->dibayar_pada && ($tanggalLunasSebelum === null || $t->dibayar_pada->gt($tanggalLunasSebelum))) {
                $bulananPerPelanggan[$plgId][$key]['tanggal_lunas'] = $t->dibayar_pada;
            }

            $sisa = max(0, $totalTagihan - $dibayarPembayaran - $dibayarKredit);
            $sisaPerBulan[$plgId][$key] = ($sisaPerBulan[$plgId][$key] ?? 0) + $sisa;
        }

        $rows = [];

        foreach ($pelangganList as $plg) {
            if (! $pelangganIds->contains($plg->id)) {
                continue;
            }

            $layanan = $plg->layananInternet;
            $mulai = $this->tanggalMulaiBerlangganan($layanan);
            $mulaiKey = $mulai !== null ? ($mulai->year * 12) + $mulai->month : null;
            $masihAktif = $layanan->contains(fn (LayananInternet $l) => $l->status === StatusLayananEnum::AKTIF);

            for ($bulan = 1; $bulan <= $bulanAkhir; $bulan++) {
                $key = ($tahun * 12) + $bulan;
                $periode = (self::NAMA_BULAN_LENGKAP[$bulan] ?? '').' '.$tahun;

                if ($mulaiKey !== null && $key < $mulaiKey) {
                    $rows[] = $this->ringkasanRow($plg, $periode, [], 0, 0, 0, 'Belum Berlangganan');

                    continue;
                }

                $own = $bulananPerPelanggan[$plg->id][$key] ?? null;
                $nunggak = $masihAktif && $this->punyaSisaSebelum($sisaPerBulan[$plg->id] ?? [], $key);

                if ($own === null && ! $nunggak) {
                    $rows[] = $this->ringkasanRow($plg, $periode, [], 0, 0, 0, 'Belum Ada Tagihan');

                    continue;
                }

                $totalTagihan = (float) ($own['total_tagihan'] ?? 0);
                $dibayarPembayaran = (float) ($own['dibayar_pembayaran'] ?? 0);
                $dibayarKredit = (float) ($own['dibayar_kredit'] ?? 0);
                $sisa = max(0, $totalTagihan - $dibayarPembayaran - $dibayarKredit);

                if ($nunggak) {
                    $status = 'Nunggak';
                } elseif ($sisa <= 0) {
                    $status = 'Lunas';
                } elseif ($dibayarPembayaran + $dibayarKredit <= 0) {
                    $status = 'Belum Bayar';
                } else {
                    $status = 'Sedang Cicil';
                }

                $tanggalLunas = $status === 'Lunas'
                    ? $this->formatTanggalLunas($own['tanggal_lunas'] ?? null)
                    : '';

                $rows[] = $this->ringkasanRow(
                    $plg,
                    $periode,
                    $own['nomor_tagihan'] ?? [],
                    $totalTagihan,
                    $dibayarPembayaran,
                    $dibayarKredit,
                    $status,
                    $tanggalLunas
                );
            }
        }

        return $rows;
    }

    /** Tanggal mulai berlangganan = tanggal_mulai_penagihan, fallback ke tanggal_aktif. */
    private function tanggalMulaiBerlangganan(Collection $layanan): ?Carbon
    {
        $mulai = null;

        foreach ($layanan as $l) {
            $candidate = $l->tanggal_mulai_penagihan ?? $l->tanggal_aktif;

            if ($candidate && ($mulai === null || $candidate->lt($mulai))) {
                $mulai = $candidate;
            }
        }

        return $mulai;
    }

    /** Ada tagihan dari bulan sebelumnya yang masih punya sisa pembayaran. */
    private function punyaSisaSebelum(array $sisaPerBulan, int $currentKey): bool
    {
        foreach ($sisaPerBulan as $key => $sisa) {
            if ($key < $currentKey && $sisa > 0) {
                return true;
            }
        }

        return false;
    }

    private function ringkasanRow(
        Pelanggan $plg,
        string $periode,
        array $nomorTagihan,
        float $totalTagihan,
        float $dibayarPembayaran,
        float $dibayarKredit,
        string $status,
        string $tanggalLunas = ''
    ): array {
        $totalTerbayar = $dibayarPembayaran + $dibayarKredit;

        return [
            'nomor_tagihan' => implode(', ', $nomorTagihan),
            'nomor_pelanggan' => $plg->nomor_pelanggan,
            'pelanggan' => $plg->nama_lengkap,
            'periode' => $periode,
            'total_tagihan' => round($totalTagihan, 2),
            'dibayar_pembayaran' => round($dibayarPembayaran, 2),
            'dibayar_kredit' => round($dibayarKredit, 2),
            'total_terbayar' => round($totalTerbayar, 2),
            'sisa' => max(0, round($totalTagihan - $totalTerbayar, 2)),
            'status' => $status,
            'tanggal_lunas' => $tanggalLunas,
        ];
    }

    private function formatTanggalLunas(?Carbon $carbon): string
    {
        if ($carbon === null) {
            return '';
        }

        return $carbon->timezone('Asia/Jakarta')->format('d F Y H:i:s');
    }

    private function buildTransaksiData(Request $request): array
    {
        $query = Pembayaran::query()
            ->with('pelanggan')
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereHas('pelanggan', fn (Builder $q2) => $q2->whereNull('reseller_id')),
                fn ($q) => $q->whereHas('pelanggan', fn (Builder $q2) => $q2->where('reseller_id', $this->resellerId))
            );

        $tahun = $request->integer('tahun', now()->year);
        $query->whereYear('dibayar_pada', $tahun);

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->where(function (Builder $q) use ($bulanList) {
                foreach ($bulanList as $b) {
                    $q->orWhereMonth('dibayar_pada', $b);
                }
            });
        }

        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $query->whereIn('pelanggan_id', array_map('intval', $pelangganIds));
        }

        $pembayaranList = $query->orderBy('dibayar_pada')->get();

        $rows = [];

        foreach ($pembayaranList as $p) {
            $rows[] = [
                'waktu' => $p->dibayar_pada?->format('d-m-Y H:i:s') ?? '',
                'nomor_pembayaran' => $this->pembayaranAllocationService->nomorPembayaran($p),
                'pelanggan' => $p->pelanggan?->nama_lengkap ?? '',
                'metode' => $p->metode_pembayaran ?? '',
                'provider' => $p->provider ?? '',
                'jumlah_dibayar' => (float) $p->jumlah_dibayar,
                'status' => $p->status->value,
                'referensi' => $p->provider_reference ?? $p->referensi_xendit ?? $p->provider_external_id ?? '',
            ];
        }

        return $rows;
    }

    private function buildAlokasiData(Request $request): array
    {
        $query = PembayaranTagihan::query()
            ->with([
                'pembayaran.pelanggan',
                'tagihan.layananInternet.pelanggan',
            ])
            ->whereHas('pembayaran', function (Builder $q) {
                $q->where('status', StatusTransaksiEnum::BERHASIL);
            })
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereHas('pembayaran.pelanggan', fn (Builder $q2) => $q2->whereNull('reseller_id')),
                fn ($q) => $q->whereHas('pembayaran.pelanggan', fn (Builder $q2) => $q2->where('reseller_id', $this->resellerId))
            );

        $tahun = $request->integer('tahun', now()->year);
        $query->whereHas('pembayaran', fn (Builder $q) => $q->whereYear('dibayar_pada', $tahun));

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->whereHas('pembayaran', function (Builder $q) use ($bulanList) {
                $q->where(function (Builder $q2) use ($bulanList) {
                    foreach ($bulanList as $b) {
                        $q2->orWhereMonth('dibayar_pada', $b);
                    }
                });
            });
        }

        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $query->whereHas('tagihan.layananInternet', fn (Builder $q) => $q->whereIn('pelanggan_id', array_map('intval', $pelangganIds)));
        }

        $alokasi = $query->get()->sortBy(fn (PembayaranTagihan $a) => $a->pembayaran?->dibayar_pada);

        $rows = [];
        $runningSisa = [];

        foreach ($alokasi as $a) {
            $tagihan = $a->tagihan;
            $pembayaran = $a->pembayaran;

            $runningSisa[$a->tagihan_id] = max(
                0,
                ($runningSisa[$a->tagihan_id] ?? (float) ($tagihan?->total_tagihan ?? 0)) - (float) $a->jumlah_dialokasikan
            );

            $rows[] = [
                'waktu' => $pembayaran?->dibayar_pada?->format('d-m-Y H:i:s') ?? '',
                'nomor_pembayaran' => $this->pembayaranAllocationService->nomorPembayaran($pembayaran),
                'nomor_tagihan' => $tagihan?->nomor_tagihan ?? '',
                'pelanggan' => $pembayaran?->pelanggan?->nama_lengkap ?? '',
                'periode' => $tagihan ? trim((self::NAMA_BULAN[$tagihan->periode_bulan] ?? '').' '.$tagihan->periode_tahun) : '',
                'jumlah_dialokasikan' => (float) $a->jumlah_dialokasikan,
                'sumber' => $pembayaran?->pakai_saldo_kredit ? 'Saldo Kredit' : 'Pembayaran',
                'sisa_tagihan' => $runningSisa[$a->tagihan_id],
            ];
        }

        return $rows;
    }

    private function buildSaldoKreditData(Request $request): array
    {
        $query = MutasiSaldoKredit::query()
            ->with(['pelanggan', 'pembayaran', 'tagihan'])
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereHas('pelanggan', fn (Builder $q2) => $q2->whereNull('reseller_id')),
                fn ($q) => $q->whereHas('pelanggan', fn (Builder $q2) => $q2->where('reseller_id', $this->resellerId))
            );

        $tahun = $request->integer('tahun', now()->year);
        $query->whereYear('created_at', $tahun);

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->where(function (Builder $q) use ($bulanList) {
                foreach ($bulanList as $b) {
                    $q->orWhereMonth('created_at', $b);
                }
            });
        }

        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $query->whereIn('pelanggan_id', array_map('intval', $pelangganIds));
        }

        $mutasi = $query->orderBy('created_at')->get();

        $rows = [];
        $runningSaldo = [];

        foreach ($mutasi as $m) {
            $jumlah = (float) $m->jumlah;
            $plgId = $m->pelanggan_id;
            $runningSaldo[$plgId] = round(($runningSaldo[$plgId] ?? 0) + ($m->jenis === 'kredit' ? $jumlah : -$jumlah), 2);

            $keterangan = array_filter([
                $m->keterangan,
                $m->pembayaran ? 'Pembayaran '.$this->pembayaranAllocationService->nomorPembayaran($m->pembayaran) : null,
                $m->tagihan ? 'Tagihan '.$m->tagihan->nomor_tagihan : null,
            ]);

            $rows[] = [
                'waktu' => $m->created_at?->format('d-m-Y H:i:s') ?? '',
                'pelanggan' => $m->pelanggan?->nama_lengkap ?? '',
                'jenis' => ucfirst((string) $m->jenis),
                'jumlah' => $jumlah,
                'sisa_saldo' => $runningSaldo[$plgId],
                'keterangan' => trim(implode(' | ', $keterangan)),
            ];
        }

        return $rows;
    }

    // ─── Query Builders ────────────────────────────────────────────

    private function pembayaranQuery(Request $request): Builder
    {
        $query = PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran_tagihan.pembayaran_id', '=', 'pembayaran.id')
            ->join('tagihan', 'pembayaran_tagihan.tagihan_id', '=', 'tagihan.id')
            ->join('layanan_internet', 'tagihan.layanan_internet_id', '=', 'layanan_internet.id')
            ->join('pelanggan', 'layanan_internet.pelanggan_id', '=', 'pelanggan.id')
            ->where('pembayaran.status', StatusTransaksiEnum::BERHASIL)
            ->when(
                $this->resellerId === null,
                fn ($q) => $q->whereNull('pelanggan.reseller_id'),
                fn ($q) => $q->where('pelanggan.reseller_id', $this->resellerId)
            );

        $this->applyDateFilter($query, $request);
        $this->applyPelangganFilter($query, $request);

        return $query;
    }

    private function tagihanQuery(Request $request): Builder
    {
        $query = Tagihan::query()
            ->whereHas('layananInternet.pelanggan', function (Builder $q) {
                if ($this->resellerId === null) {
                    $q->whereNull('reseller_id');
                } else {
                    $q->where('reseller_id', $this->resellerId);
                }
            });

        $tahun = $request->integer('tahun', now()->year);
        $query->where('periode_tahun', $tahun);

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->whereIn('periode_bulan', $bulanList);
        }

        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $ids = array_map('intval', $pelangganIds);

            $query->whereHas('layananInternet', function (Builder $q) use ($ids) {
                $q->whereIn('pelanggan_id', $ids);
            });
        }

        return $query;
    }

    // ─── Filter Helpers ────────────────────────────────────────────

    private function applyDateFilter(Builder $query, Request $request): void
    {
        $tahun = $request->integer('tahun', now()->year);
        $query->whereYear('pembayaran.dibayar_pada', $tahun);

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->where(function (Builder $q) use ($bulanList) {
                foreach ($bulanList as $b) {
                    $q->orWhereMonth('pembayaran.dibayar_pada', $b);
                }
            });
        }
    }

    private function applyPelangganFilter(Builder $query, Request $request): void
    {
        if ($this->resellerId !== null) {
            $query->where('pelanggan.reseller_id', $this->resellerId);
        }

        $pelangganIds = $request->input('pelanggan_ids');

        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $ids = array_map('intval', $pelangganIds);

            $query->whereIn('pelanggan.id', $ids);
        }
    }

    private function parseBulanArray(Request $request): ?array
    {
        $raw = $request->input('bulan');

        if (! is_array($raw) || count($raw) === 0) {
            return null;
        }

        $valid = array_filter(array_map('intval', $raw), fn ($b) => $b >= 1 && $b <= 12);

        return count($valid) > 0 ? array_values($valid) : null;
    }

    // ─── Tren ──────────────────────────────────────────────────────

    private function hitungTren(Builder $query, Request $request): array
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulanList = $this->parseBulanArray($request);

        if ($bulanList !== null && count($bulanList) === 1) {
            return $this->trenHarian($query, $tahun, $bulanList[0]);
        }

        if ($bulanList !== null && count($bulanList) > 1) {
            return $this->trenBulananFiltered($query, $tahun, $bulanList);
        }

        return $this->trenBulanan($query, $tahun);
    }

    private function trenHarian(Builder $query, int $tahun, int $bulan): array
    {
        $rows = (clone $query)
            ->whereMonth('pembayaran.dibayar_pada', $bulan)
            ->selectRaw(
                'date(pembayaran.dibayar_pada) as tanggal,
                SUM(pembayaran_tagihan.jumlah_dialokasikan) as total'
            )
            ->groupBy('tanggal')
            ->get();

        $jumlahHari = now()->setDate($tahun, $bulan, 1)->daysInMonth;
        $tren = [];
        for ($hari = 1; $hari <= $jumlahHari; $hari++) {
            $tgl = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
            $tren[] = ['bulan' => (string) $hari, 'jumlah' => (int) ($rows[$tgl] ?? 0)];
        }

        return $tren;
    }

    private function trenBulananFiltered(Builder $query, int $tahun, array $bulanList): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'date(pembayaran.dibayar_pada) as tanggal,
                SUM(pembayaran_tagihan.jumlah_dialokasikan) as total'
            )
            ->groupBy('tanggal')
            ->get();

        $groupByMonth = [];
        foreach ($rows as $row) {
            $m = (int) Carbon::parse($row->tanggal)->format('m');
            $groupByMonth[$m] = ($groupByMonth[$m] ?? 0) + (float) $row->total;
        }

        $tren = [];
        foreach ($bulanList as $m) {
            $tren[] = ['bulan' => self::NAMA_BULAN[$m] ?? "Bulan {$m}", 'jumlah' => (int) ($groupByMonth[$m] ?? 0)];
        }

        return $tren;
    }

    private function trenBulanan(Builder $query, int $tahun): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'date(pembayaran.dibayar_pada) as tanggal,
                SUM(pembayaran_tagihan.jumlah_dialokasikan) as total'
            )
            ->groupBy('tanggal')
            ->get();

        $groupByMonth = [];
        foreach ($rows as $row) {
            $m = (int) Carbon::parse($row->tanggal)->format('m');
            $groupByMonth[$m] = ($groupByMonth[$m] ?? 0) + (float) $row->total;
        }

        $tren = [];
        for ($m = 1; $m <= 12; $m++) {
            $tren[] = ['bulan' => self::NAMA_BULAN[$m], 'jumlah' => (int) ($groupByMonth[$m] ?? 0)];
        }

        return $tren;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    private function filterMeta(Request $request): array
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulanList = $this->parseBulanArray($request);

        return [
            'tahun' => $tahun,
            'bulan' => $bulanList,
        ];
    }

    private function labelPeriode(Request $request): string
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulanList = $this->parseBulanArray($request);

        if ($bulanList === null) {
            return 'Tahun '.$tahun;
        }

        $namaBulan = array_map(fn ($b) => self::NAMA_BULAN[$b] ?? "Bulan {$b}", $bulanList);

        return implode(', ', $namaBulan).' '.$tahun;
    }

    private function labelStatus(StatusPembayaranEnum $status): string
    {
        return match ($status) {
            StatusPembayaranEnum::BELUM_BAYAR => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR => 'Lunas',
            default => '-',
        };
    }

    private function rupiah($nilai): string
    {
        return 'Rp '.number_format((float) ($nilai ?? 0), 0, ',', '.');
    }
}
