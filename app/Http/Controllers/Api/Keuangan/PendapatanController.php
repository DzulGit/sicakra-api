<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Exports\PendapatanReportExport;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class PendapatanController extends Controller
{
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    private const NAMA_BULAN_LENGKAP = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const KOLOM = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'];

    /** Ringkasan pendapatan filterable per bulan/tahun. */
    public function index(Request $request)
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulan = $request->integer('bulan', 0);
        $punyaBulan = $bulan >= 1 && $bulan <= 12;

        $queryPembayaran = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->whereYear('dibayar_pada', $tahun);

        if ($punyaBulan) {
            $queryPembayaran->whereMonth('dibayar_pada', $bulan);
        }

        $tren = $punyaBulan
            ? $this->trenHarian($queryPembayaran->clone(), $tahun, $bulan)
            : $this->trenBulanan($queryPembayaran->clone(), $tahun);

        $stats = [
            'total_pendapatan' => $this->rupiah((clone $queryPembayaran)->sum('jumlah_dibayar')),
            'jumlah_pembayaran' => (clone $queryPembayaran)->count(),
            'tagihan_dibuat' => $this->tagihanPeriode($tahun, $punyaBulan ? $bulan : null)->count(),
        ];

        $distribusiPembayaran = $this->tagihanPeriode($tahun, $punyaBulan ? $bulan : null)
            ->selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $this->labelStatus($item->status_pembayaran),
                'jumlah' => (int) $item->jumlah,
            ]);

        $pembayaranTerbaru = (clone $queryPembayaran)
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
                'filter' => ['tahun' => $tahun, 'bulan' => $punyaBulan ? $bulan : null],
                'stats' => $stats,
                'tren' => $tren,
                'distribusi_pembayaran' => $distribusiPembayaran,
                'pembayaran_terbaru' => $pembayaranTerbaru,
            ],
        ]);
    }

    /** Laporan pendapatan bulanan dalam bentuk PDF. */
    public function report(Request $request)
    {
        [$tahun, $bulan] = $this->periode($request);

        $pembayaran = $this->pembayaranPeriode($tahun, $bulan)->get();
        $detail = $this->detailPembayaran($pembayaran);
        $ringkasan = $this->ringkasan($pembayaran);

        $pdf = Pdf::loadView('pdf.report-pendapatan', [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'namaBulan' => self::NAMA_BULAN_LENGKAP[$bulan],
            'filterLabel' => $this->filterLabel($tahun, $bulan),
            'detail' => $detail,
            'ringkasan' => $ringkasan,
            'dicetakPada' => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream("laporan-pendapatan-{$bulan}-{$tahun}.pdf");
    }

    /** Laporan pendapatan bulanan dalam bentuk Excel (.xlsx). */
    public function reportExcel(Request $request)
    {
        [$tahun, $bulan] = $this->periode($request);

        $pembayaran = $this->pembayaranPeriode($tahun, $bulan)->get();
        $detail = $this->detailPembayaran($pembayaran);

        $file = Excel::raw(new PendapatanReportExport(
            $detail,
            'Laporan Pendapatan Bulanan',
            $this->filterLabel($tahun, $bulan),
            self::NAMA_BULAN_LENGKAP[$bulan].' '.$tahun,
            $this->ringkasan($pembayaran),
        ), \Maatwebsite\Excel\Excel::XLSX);

        return response($file, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"laporan-pendapatan-{$bulan}-{$tahun}.xlsx\"",
        ]);
    }

    private function periode(Request $request): array
    {
        $tahun = $request->integer('tahun', now()->year);
        $bulan = $request->integer('bulan', now()->month);
        if ($bulan < 1 || $bulan > 12) {
            $bulan = now()->month;
        }

        return [$tahun, $bulan];
    }

    private function pembayaranPeriode(int $tahun, int $bulan): Builder
    {
        return Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
            ->whereMonth('dibayar_pada', $bulan)
            ->whereYear('dibayar_pada', $tahun)
            ->with('tagihan.layananInternet.pelanggan', 'tagihan.layananInternet.paketInternet')
            ->orderBy('dibayar_pada');
    }

    /** Baris detail laporan — satu sumber data dipakai Excel & PDF biar konsisten. */
    private function detailPembayaran(Collection $pembayaran): array
    {
        return $pembayaran->values()->map(function (Pembayaran $item, int $i) {
            $tagihan = $item->tagihan;
            $layanan = $tagihan?->layananInternet;
            $pelanggan = $layanan?->pelanggan;
            $paket = $layanan?->paketInternet;

            return [
                'no' => $i + 1,
                'tanggal_bayar' => $item->dibayar_pada?->format('d/m/Y H:i') ?? '-',
                'nomor_tagihan' => $tagihan?->nomor_tagihan ?? '-',
                'periode_tagihan' => $this->labelPeriodeTagihan($tagihan),
                'nomor_pelanggan' => $pelanggan?->nomor_pelanggan ?? '-',
                'nama_pelanggan' => $pelanggan?->nama_lengkap ?? '-',
                'nik' => $pelanggan?->nik ?? '-',
                'nomor_hp' => $pelanggan?->nomor_hp ?? '-',
                'alamat' => $layanan?->alamat_pemasangan ?? '-',
                'nomor_layanan' => $layanan?->nomor_layanan ?? '-',
                'nama_paket' => $tagihan?->nama_paket_snapshot
                    ?? $paket?->nama_paket
                    ?? $layanan?->nama_paket_custom
                    ?? '-',
                'kecepatan' => $this->labelKecepatan($paket?->kecepatan_mbps, $layanan?->kecepatan_custom_mbps),
                'metode' => $item->metode_pembayaran ? ucwords((string) $item->metode_pembayaran) : '-',
                'jatuh_tempo' => $tagihan?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-',
                'total_tagihan' => (float) ($tagihan?->total_tagihan ?? 0),
                'jumlah_dibayar' => (float) ($item->jumlah_dibayar ?? 0),
                'status' => $this->labelStatus($tagihan?->status_pembayaran),
            ];
        })->all();
    }

    private function ringkasan(Collection $pembayaran): array
    {
        $total = (float) $pembayaran->sum('jumlah_dibayar');
        $jumlah = $pembayaran->count();

        return [
            'jumlah_transaksi' => $jumlah,
            'total_pendapatan' => $total,
            'rata_rata' => $jumlah > 0 ? $total / $jumlah : 0,
            'pelanggan_unik' => $pembayaran
                ->pluck('tagihan.layananInternet.pelanggan.nama_lengkap')
                ->filter()
                ->unique()
                ->count(),
        ];
    }

    /** Label periode penagihan tagihan, mis. "Feb 2026" atau "Feb – Apr 2026" utk multi-bulan. */
    private function labelPeriodeTagihan(?Tagihan $tagihan): string
    {
        if (! $tagihan) {
            return '-';
        }

        $mulai = self::NAMA_BULAN[$tagihan->periode_bulan] ?? '?';
        $akhir = $tagihan->periodeBulanAkhir();
        $akhirLabel = (self::NAMA_BULAN[$akhir['bulan']] ?? '?').' '.$akhir['tahun'];

        return $akhir === ['bulan' => $tagihan->periode_bulan, 'tahun' => $tagihan->periode_tahun]
            ? "{$mulai} {$tagihan->periode_tahun}"
            : "{$mulai} {$tagihan->periode_tahun} – {$akhirLabel}";
    }

    private function labelKecepatan($kecepatanPaket, $kecepatanCustom): string
    {
        $nilai = (float) ($kecepatanPaket ?? $kecepatanCustom ?? 0);

        return $nilai > 0 ? rtrim(rtrim(number_format($nilai, 2, ',', ''), '0'), ',,').' Mbps' : '-';
    }

    private function filterLabel(int $tahun, int $bulan): string
    {
        return self::NAMA_BULAN[$bulan].' '.$tahun;
    }

    private function tagihanPeriode(int $tahun, ?int $bulan): Builder
    {
        $query = Tagihan::query()->where('periode_tahun', $tahun);

        if ($bulan) {
            $query->where('periode_bulan', $bulan);
        }

        return $query;
    }

    private function trenHarian(Builder $query, int $tahun, int $bulan): array
    {
        $data = $query->selectRaw("to_char(dibayar_pada, 'YYYY-MM-DD') as tanggal, SUM(jumlah_dibayar) as total")
            ->groupBy('tanggal')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->tanggal => (float) $item->total]);

        $jumlahHari = now()->setDate($tahun, $bulan, 1)->daysInMonth;
        $tren = [];
        for ($hari = 1; $hari <= $jumlahHari; $hari++) {
            $tgl = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
            $tren[] = ['bulan' => (string) $hari, 'jumlah' => (int) ($data[$tgl] ?? 0)];
        }

        return $tren;
    }

    private function trenBulanan(Builder $query, int $tahun): array
    {
        $data = $query->selectRaw("to_char(dibayar_pada, 'YYYY-MM') as bulan, SUM(jumlah_dibayar) as total")
            ->groupBy('bulan')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->bulan => (float) $item->total]);

        $tren = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%04d-%02d', $tahun, $m);
            $tren[] = ['bulan' => self::NAMA_BULAN[$m], 'jumlah' => (int) ($data[$key] ?? 0)];
        }

        return $tren;
    }

    private function labelStatus(?StatusPembayaranEnum $status): string
    {
        return match ($status) {
            StatusPembayaranEnum::BELUM_BAYAR => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR => 'Sudah Bayar',
            StatusPembayaranEnum::KEDALUWARSA => 'Kedaluwarsa',
            default => '-',
        };
    }

    private function rupiah($nilai): string
    {
        return 'Rp '.number_format((float) ($nilai ?? 0), 0, ',', '.');
    }
}
