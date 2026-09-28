<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusTransaksiEnum;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TimTeknisi;

class DashboardSuperAdminController extends Controller
{
    public function index()
    {
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

        $stats = [
            'total_pelanggan' => Pelanggan::count(),
            'pelanggan_aktif' => Pelanggan::whereHas('layananInternet', fn ($q) => $q->where('status', 'aktif'))->count(),
            'pertumbuhan_pelanggan' => Pelanggan::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'total_teknisi' => Admin::where('peran', PeranAdminEnum::TEKNISI)->where('status_aktif', true)->count(),
            'jumlah_tim_teknisi' => TimTeknisi::count(),
            'pendapatan_bulan_ini' => $this->rupiah(
                Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
                    ->whereMonth('dibayar_pada', now()->month)
                    ->whereYear('dibayar_pada', now()->year)
                    ->sum('jumlah_dibayar')
            ),
            'kendala_aktif' => LaporanKendala::whereIn('status', [
                StatusLaporanEnum::MENUNGGU,
                StatusLaporanEnum::DIPROSES,
                StatusLaporanEnum::DITUGASKAN,
            ])->count(),
        ];

        $pelangganPerBulan = Pelanggan::where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['created_at'])
            ->groupBy(fn ($p) => $p->created_at->format('Y-m'))
            ->map(fn ($items) => $items->count());

        $trenPelanggan = [];
        for ($i = 11; $i >= 0; $i--) {
            $titik = now()->subMonths($i);
            $trenPelanggan[] = [
                'bulan' => $namaBulan[(int) $titik->format('n')],
                'jumlah' => $pelangganPerBulan[$titik->format('Y-m')] ?? 0,
            ];
        }

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

        $aktivitasTerbaru = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)
            ->with('pelanggan')
            ->latest('dibayar_pada')
            ->take(8)
            ->get()
            ->map(fn (Pembayaran $pembayaran) => [
                'id' => $pembayaran->id,
                'tipe' => 'pembayaran',
                'deskripsi' => 'Pembayaran ' . $this->rupiah($pembayaran->jumlah_dibayar),
                'pengguna' => $pembayaran->pelanggan?->nama_lengkap ?? '-',
                'waktu' => $pembayaran->dibayar_pada?->diffForHumans() ?? '-',
            ]);

        return response()->json([
            'data' => [
                'stats' => $stats,
                'tren_pelanggan' => $trenPelanggan,
                'tren_pendapatan' => $trenPendapatan,
                'aktivitas_terbaru' => $aktivitasTerbaru,
                'kesehatan_sistem' => null,
            ],
        ]);
    }

    private function rupiah($nilai): string
    {
        return 'Rp ' . number_format((float) ($nilai ?? 0), 0, ',', '.');
    }
}