<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Exports\PendapatanMatrixExport;
use App\Http\Controllers\Controller;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class PendapatanController extends Controller
{
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /** Daftar pelanggan untuk dropdown multi-select. */
    public function pelangganList()
    {
        return response()->json([
            'data' => Pelanggan::query()
                ->select('id', 'nama_lengkap', 'nomor_pelanggan')
                ->orderBy('nama_lengkap')
                ->get(),
        ]);
    }

    /** Ringkasan pendapatan dengan filter tahun, bulan[], pelanggan_ids[]. */
    public function index(Request $request)
    {
        $query = $this->pembayaranQuery($request);

        $stats = [
            'total_pendapatan' => $this->rupiah((clone $query)->sum('jumlah_dibayar')),
            'jumlah_pembayaran' => (clone $query)->count(),
            'tagihan_dibuat' => $this->tagihanQuery($request)->count(),
        ];

        $distribusiPembayaran = $this->tagihanQuery($request)
            ->selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $this->labelStatus($item->status_pembayaran->value),
                'jumlah' => (int) $item->jumlah,
            ]);

        $tren = $this->hitungTren(clone $query, $request);

        $pembayaranTerbaru = (clone $query)
            ->with('tagihan.layananInternet.pelanggan')
            ->latest('dibayar_pada')
            ->take(10)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'nomor_tagihan' => $item->tagihan?->nomor_tagihan,
                'pelanggan' => $item->tagihan?->layananInternet?->pelanggan?->nama_lengkap,
                'jumlah' => $this->rupiah($item->jumlah_dibayar),
                'status' => StatusTransaksiEnum::BERHASIL->value,
                'waktu' => $item->dibayar_pada?->format('d M Y H:i'),
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

    /** Laporan pendapatan PDF (matriks). */
    public function report(Request $request)
    {
        $matrix = $this->buildMatrix($request);

        $pdf = Pdf::loadView('pdf.report-pendapatan', [
            'labelPeriode' => $this->labelPeriode($request),
            'matrix' => $matrix['data'],
            'kolomBulan' => $matrix['kolomBulan'],
            'total' => $matrix['total'],
        ]);

        $slug = str($this->labelPeriode($request))->slug()->toString();
        return $pdf->stream("laporan-pendapatan-{$slug}.pdf");
    }

    /** Laporan pendapatan Excel (matriks). */
    public function reportExcel(Request $request)
    {
        $matrix = $this->buildMatrix($request);

        $file = Excel::raw(new PendapatanMatrixExport(
            $matrix['data'],
            $matrix['kolomBulan'],
            $this->labelPeriode($request),
            $matrix['total'],
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
        $pelangganQuery = Pelanggan::query()->select('id', 'nama_lengkap', 'nomor_pelanggan');
        $pelangganIds = $request->input('pelanggan_ids');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $pelangganQuery->whereIn('id', array_map('intval', $pelangganIds));
        }
        $pelangganList = $pelangganQuery->orderBy('nama_lengkap')->get();

        // 2. Ambil semua layanan internet aktif beserta tanggal_aktif
        $layananQuery = LayananInternet::query()
            ->select('id', 'pelanggan_id', 'tanggal_aktif', 'status')
            ->where('status', 'aktif');
        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $layananQuery->whereIn('pelanggan_id', array_map('intval', $pelangganIds));
        }
        $layananAktif = $layananQuery->get()->keyBy('id');

        // 3. Ambil tagihan untuk tahun + bulan yang diminta
        $tagihanQuery = Tagihan::query()
            ->select('id', 'layanan_internet_id', 'periode_bulan', 'periode_tahun', 'status_pembayaran', 'total_tagihan')
            ->where('periode_tahun', $tahun)
            ->whereIn('periode_bulan', $bulanList);
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
            $pembayaranBerhasil = Pembayaran::query()
                ->select('tagihan_id', 'jumlah_dibayar', 'dibayar_pada')
                ->where('status', StatusTransaksiEnum::BERHASIL)
                ->whereIn('tagihan_id', $tagihanIds)
                ->get()
                ->groupBy('tagihan_id');

            foreach ($pembayaranBerhasil as $tagihanId => $bayar) {
                $totalBayar = $bayar->sum('jumlah_dibayar');
                $tanggalBayar = $bayar->max('dibayar_pada');
                $pembayaranMap[$tagihanId] = [
                    'nominal' => (float) $totalBayar,
                    'tanggal' => $tanggalBayar instanceof Carbon ? $tanggalBayar->format('d-m-Y H:i:s') : '',
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

                if (!$aktifDiBulan) {
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

                if (!$tagihanDitemukan) {
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

    // ─── Query Builders ────────────────────────────────────────────

    private function pembayaranQuery(Request $request): Builder
    {
        $query = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL);

        $this->applyDateFilter($query, $request);
        $this->applyPelangganFilter($query, $request);

        return $query;
    }

    private function tagihanQuery(Request $request): Builder
    {
        $query = Tagihan::query();

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
        $query->whereYear('dibayar_pada', $tahun);

        $bulanList = $this->parseBulanArray($request);
        if ($bulanList !== null) {
            $query->where(function (Builder $q) use ($bulanList) {
                foreach ($bulanList as $b) {
                    $q->orWhereMonth('dibayar_pada', $b);
                }
            });
        }
    }

    private function applyPelangganFilter(Builder $query, Request $request): void
    {
        $pelangganIds = $request->input('pelanggan_ids');

        if (is_array($pelangganIds) && count($pelangganIds) > 0) {
            $ids = array_map('intval', $pelangganIds);
            $query->whereHas('tagihan.layananInternet', function (Builder $q) use ($ids) {
                $q->whereIn('pelanggan_id', $ids);
            });
        }
    }

    private function parseBulanArray(Request $request): ?array
    {
        $raw = $request->input('bulan');

        if (!is_array($raw) || count($raw) === 0) {
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
            ->whereMonth('dibayar_pada', $bulan)
            ->selectRaw("date(dibayar_pada) as tanggal, SUM(jumlah_dibayar) as total")
            ->groupBy('tanggal')
            ->get()
            ->mapWithKeys(fn ($item) => [(string) $item->tanggal => (float) $item->total]);

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
            ->selectRaw("date(dibayar_pada) as tanggal, SUM(jumlah_dibayar) as total")
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
            ->selectRaw("date(dibayar_pada) as tanggal, SUM(jumlah_dibayar) as total")
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

    private function labelStatus(string $status): string
    {
        return match ($status) {
            StatusPembayaranEnum::BELUM_BAYAR->value => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR->value => 'Sudah Bayar',
            StatusPembayaranEnum::KEDALUWARSA->value => 'Kedaluwarsa',
            default => $status,
        };
    }

    private function rupiah($nilai): string
    {
        return 'Rp '.number_format((float) ($nilai ?? 0), 0, ',', '.');
    }
}
