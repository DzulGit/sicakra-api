<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Carbon\Carbon;

class DashboardKeuanganController extends Controller
{
    public function __construct(
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    public function index()
    {
        $hariIni = now()->startOfDay();
        $batasJatuhTempo = $hariIni->copy()->addDays(7);

        /*
         * Jatuh tempo = akhir bulan dari periode tagihan.
         *
         * Contoh:
         * periode_bulan = 8
         * periode_tahun = 2026
         * => jatuh tempo = 31 Agustus 2026
         */

        $tertunggak = Tagihan::query()
            ->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
            ->where(function ($query) use ($hariIni) {
                $query
                    ->where('periode_tahun', '<', $hariIni->year)
                    ->orWhere(function ($query) use ($hariIni) {
                        $query
                            ->where('periode_tahun', $hariIni->year)
                            ->where('periode_bulan', '<', $hariIni->month);
                    });
            });

        $periodeJatuhTempoMingguIni = collect();

        for ($i = 0; $i <= 1; $i++) {
            $periode = $hariIni->copy()
                ->startOfMonth()
                ->addMonths($i);

            $jatuhTempo = $periode->copy()->endOfMonth();

            if ($jatuhTempo->betweenIncluded($hariIni, $batasJatuhTempo)) {
                $periodeJatuhTempoMingguIni->push([
                    'bulan' => $periode->month,
                    'tahun' => $periode->year,
                ]);
            }
        }

        $jatuhTempoMingguIni = Tagihan::query()
            ->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
            ->where(function ($query) use ($periodeJatuhTempoMingguIni) {
                foreach ($periodeJatuhTempoMingguIni as $periode) {
                    $query->orWhere(function ($query) use ($periode) {
                        $query
                            ->where('periode_bulan', $periode['bulan'])
                            ->where('periode_tahun', $periode['tahun']);
                    });
                }
            });

        $pembayaranHariIni = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->whereDate('dibayar_pada', $hariIni);

        $pendapatanBulanIni = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->whereMonth('dibayar_pada', now()->month)
            ->whereYear('dibayar_pada', now()->year)
            ->sum('jumlah_dibayar');

        /*
         * Nilai tunggakan = sisa tagihan (bukan total_tagihan),
         * karena pembayaran bisa parsial / pakai saldo deposit.
         */
        $totalTertunggak = (clone $tertunggak)
            ->get()
            ->reduce(
                fn (float $total, Tagihan $item) =>
                    $total + $this->pembayaranAllocationService
                        ->hitungSisaTagihan($item),
                0
            );

        $stats = [
            'pembayaran_hari_ini' => (clone $pembayaranHariIni)->count(),
            'total_pembayaran_hari_ini' => $this->rupiah(
                (clone $pembayaranHariIni)->sum('jumlah_dibayar')
            ),
            'tagihan_tertunggak' => (clone $tertunggak)->count(),
            'total_tertunggak' => $this->rupiah($totalTertunggak),
            'jatuh_tempo_minggu_ini' => (clone $jatuhTempoMingguIni)->count(),
            'pendapatan_bulan_ini' => $this->rupiah($pendapatanBulanIni),
        ];

        /*
         * Tren pendapatan 12 bulan terakhir.
         */
        $namaBulan = [
            1 => 'Jan',
            'Feb',
            'Mar',
            'Apr',
            'Mei',
            'Jun',
            'Jul',
            'Agu',
            'Sep',
            'Okt',
            'Nov',
            'Des',
        ];

        $pendapatanPerBulan = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->where(
                'dibayar_pada',
                '>=',
                now()->subMonths(11)->startOfMonth()
            )
            ->get(['dibayar_pada', 'jumlah_dibayar'])
            ->groupBy(
                fn (Pembayaran $p) => $p->dibayar_pada->format('Y-m')
            )
            ->map(
                fn ($items) => (float) $items->sum('jumlah_dibayar')
            );

        $trenPendapatan = [];

        for ($i = 11; $i >= 0; $i--) {
            $titik = now()->subMonths($i);

            $trenPendapatan[] = [
                'bulan' => $namaBulan[(int) $titik->format('n')],
                'jumlah' => (int) (
                    $pendapatanPerBulan[$titik->format('Y-m')] ?? 0
                ),
            ];
        }

        /*
         * Distribusi status tagihan.
         *
         * BELUM_DITERBITKAN sengaja tidak ditampilkan sebagai
         * status pembayaran karena belum menjadi tagihan yang
         * bisa dibayar pelanggan.
         */
        $labelStatus = [
            StatusPembayaranEnum::BELUM_BAYAR->value => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR->value => 'Sudah Bayar',
        ];

        $distribusiPembayaran = Tagihan::query()
            ->whereIn('status_pembayaran', [
                StatusPembayaranEnum::BELUM_BAYAR,
                StatusPembayaranEnum::SUDAH_BAYAR,
            ])
            ->selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $labelStatus[$item->status_pembayaran->value]
                    ?? $item->status_pembayaran->value,
                'jumlah' => (int) $item->jumlah,
            ]);

        /*
         * Pembayaran terbaru.
         *
         * Jangan menggunakan Pembayaran.tagihan_id karena sekarang
         * satu pembayaran bisa mengalokasikan ke banyak tagihan.
         */
        $pembayaranTerbaru = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
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
                    ->implode(', '),
                'pelanggan' => $pembayaran->pelanggan?->nama_lengkap,
                'jumlah' => $this->rupiah($pembayaran->jumlah_dibayar),
                'status' => StatusTransaksiEnum::BERHASIL->value,
                'waktu' => $pembayaran->dibayar_pada?->format('d M Y H:i'),
            ]);

        /*
         * Tagihan yang jatuh tempo dalam 7 hari.
         *
         * Tambahkan tanggal jatuh tempo sebagai nilai hasil perhitungan,
         * bukan kolom database.
         */
        $tagihanAkanJatuhTempo = (clone $jatuhTempoMingguIni)
            ->with('layananInternet.pelanggan')
            ->get()
            ->sortBy(function (Tagihan $tagihan) {
                return Carbon::create(
                    $tagihan->periode_tahun,
                    $tagihan->periode_bulan,
                    1
                )->endOfMonth();
            })
            ->values()
            ->map(function (Tagihan $tagihan) {
                $jatuhTempo = Carbon::create(
                    $tagihan->periode_tahun,
                    $tagihan->periode_bulan,
                    1
                )->endOfMonth();

                return [
                    ...$tagihan->toArray(),
                    'jatuh_tempo' => $jatuhTempo->toDateString(),
                ];
            });

        return response()->json([
            'data' => [
                'stats' => $stats,
                'tren_pendapatan' => $trenPendapatan,
                'distribusi_pembayaran' => $distribusiPembayaran,
                'pembayaran_terbaru' => $pembayaranTerbaru,
                'tagihan_akan_jatuh_tempo' => $tagihanAkanJatuhTempo,
            ],
        ]);
    }

    private function rupiah($nilai): string
    {
        return 'Rp ' . number_format(
            (float) ($nilai ?? 0),
            0,
            ',',
            '.'
        );
    }
}