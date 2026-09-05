<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Tagihan;

class DashboardKeuanganController extends Controller
{
    public function index()
    {
        $hariIni = now()->toDateString();

        $pembayaranHariIni = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
            ->whereDate('dibayar_pada', $hariIni);

        $tertunggak = Tagihan::where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
            ->whereDate('tanggal_jatuh_tempo', '<', $hariIni);

        $jatuhTempoMingguIni = Tagihan::where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
            ->whereBetween('tanggal_jatuh_tempo', [$hariIni, now()->addDays(7)->toDateString()]);

        $pendapatanBulanIni = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
            ->whereMonth('dibayar_pada', now()->month)
            ->whereYear('dibayar_pada', now()->year)
            ->sum('jumlah_dibayar');

        $stats = [
            'pembayaran_hari_ini' => (clone $pembayaranHariIni)->count(),
            'total_pembayaran_hari_ini' => $this->rupiah((clone $pembayaranHariIni)->sum('jumlah_dibayar')),
            'tagihan_tertunggak' => (clone $tertunggak)->count(),
            'total_tertunggak' => $this->rupiah((clone $tertunggak)->sum('total_tagihan')),
            'jatuh_tempo_minggu_ini' => $jatuhTempoMingguIni->count(),
            'pendapatan_bulan_ini' => $this->rupiah($pendapatanBulanIni),
        ];

        $namaBulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        $pendapatanPerBulan = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
            ->where('dibayar_pada', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['dibayar_pada', 'jumlah_dibayar'])
            ->groupBy(fn (Pembayaran $p) => $p->dibayar_pada->format('Y-m'))
            ->map(fn ($items) => (float) $items->sum('jumlah_dibayar'));

        $trenPendapatan = [];
        for ($i = 11; $i >= 0; $i--) {
            $titik = now()->subMonths($i);
            $trenPendapatan[] = [
                'bulan' => $namaBulan[(int) $titik->format('n')],
                'jumlah' => (int) ($pendapatanPerBulan[$titik->format('Y-m')] ?? 0),
            ];
        }

        $labelStatus = [
            StatusPembayaranEnum::BELUM_BAYAR->value => 'Belum Bayar',
            StatusPembayaranEnum::SUDAH_BAYAR->value => 'Sudah Bayar',
            StatusPembayaranEnum::KEDALUWARSA->value => 'Kedaluwarsa',
        ];

        $distribusiPembayaran = Tagihan::selectRaw('status_pembayaran, count(*) as jumlah')
            ->groupBy('status_pembayaran')
            ->get()
            ->map(fn ($item) => [
                'status' => $item->status_pembayaran->value,
                'label' => $labelStatus[$item->status_pembayaran->value] ?? $item->status_pembayaran->value,
                'jumlah' => (int) $item->jumlah,
            ]);

        $pembayaranTerbaru = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
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

        $tagihanJatuhTempo = $jatuhTempoMingguIni
            ->with('layananInternet.pelanggan')
            ->orderBy('tanggal_jatuh_tempo')
            ->take(10)
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'tren_pendapatan' => $trenPendapatan,
                'distribusi_pembayaran' => $distribusiPembayaran,
                'pembayaran_terbaru' => $pembayaranTerbaru,
                'tagihan_akan_jatuh_tempo' => $tagihanJatuhTempo,
            ],
        ]);
    }

    private function rupiah($nilai): string
    {
        return 'Rp '.number_format((float) ($nilai ?? 0), 0, ',', '.');
    }
}
