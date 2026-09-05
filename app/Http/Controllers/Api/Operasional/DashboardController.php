<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\JenisPermohonanEnum;
use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\LaporanKendala;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use App\Models\TimTeknisi;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();

        $stats = [
            'permohonan_baru_hari_ini' => PermohonanLayanan::whereDate('created_at', $today)->count(),
            'menunggu_verifikasi' => PermohonanLayanan::where('status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI)->count(),
            'pemasangan_hari_ini' => JadwalKerja::whereDate('tanggal_kerja', $today)->count(),
            'teknisi_aktif' => Admin::where('peran', PeranAdminEnum::TEKNISI)->where('status_aktif', true)->count(),
            'kendala_aktif' => LaporanKendala::whereIn('status', [
                StatusLaporanEnum::MENUNGGU, StatusLaporanEnum::DIPROSES, StatusLaporanEnum::DITUGASKAN,
            ])->count(),
        ];

        $permohonanLayanan = $this->ringkasanPermohonan(JenisPermohonanEnum::RELOKASI);
        $pendaftarBaru = $this->ringkasanPermohonan(JenisPermohonanEnum::PEMASANGAN_BARU);

        $pelanggan = [
            'total' => Pelanggan::count(),
            'total_aktif' => Pelanggan::whereHas('layananInternet', fn ($q) => $q->where('status', 'aktif'))->count(),
            'terbaru' => Pelanggan::latest()->take(20)->get(),
        ];

        $paketInternet = [
            'total' => PaketInternet::count(),
            'total_aktif' => PaketInternet::where('status_aktif', true)->count(),
            'terbaru' => PaketInternet::latest()->take(20)->get(),
        ];

        $laporanKendala = [
            'total_aktif' => LaporanKendala::whereIn('status', [
                StatusLaporanEnum::MENUNGGU, StatusLaporanEnum::DIPROSES, StatusLaporanEnum::DITUGASKAN,
            ])->count(),
            'by_status' => LaporanKendala::selectRaw('status, count(*) as total')
                ->groupBy('status')->pluck('total', 'status'),
            'terbaru' => LaporanKendala::with('layananInternet.pelanggan')->latest()->take(20)->get(),
        ];

        $timTeknisi = [
            'total' => TimTeknisi::count(),
            'total_aktif' => TimTeknisi::where('status_aktif', true)->count(),
            'anggota_aktif' => Admin::where('peran', PeranAdminEnum::TEKNISI)->where('status_aktif', true)->count(),
            'terbaru' => TimTeknisi::withCount('anggota')->latest()->take(20)->get(),
        ];

        $trenPermohonan = PermohonanLayanan::latest('created_at')->limit(1000)->get(['created_at'])
            ->groupBy(fn ($p) => $p->created_at->format('Y-m'))
            ->map(fn ($items) => ['bulan' => $items->first()->created_at->format('Y-m'), 'jumlah' => $items->count()])
            ->values();

        $distribusiStatus = PermohonanLayanan::selectRaw('status, count(*) as jumlah')
            ->groupBy('status')->get()->map(fn ($item) => [
                'status' => $item->status,
                'label' => $item->status instanceof StatusPermohonanEnum ? $item->status->label() : $item->status,
                'jumlah' => (int) $item->jumlah,
            ]);

        $permohonanTerbaru = PermohonanLayanan::with('pelanggan')
            ->latest()->take(20)->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'permohonan_layanan' => $permohonanLayanan,
                'pendaftar_baru' => $pendaftarBaru,
                'pelanggan' => $pelanggan,
                'paket_internet' => $paketInternet,
                'laporan_kendala' => $laporanKendala,
                'tim_teknisi' => $timTeknisi,
                'tren_permohonan' => $trenPermohonan,
                'distribusi_status' => $distribusiStatus,
                'permohonan_terbaru' => $permohonanTerbaru,
            ],
        ]);
    }

    private function ringkasanPermohonan(JenisPermohonanEnum $jenis): array
    {
        $query = PermohonanLayanan::where('jenis_permohonan', $jenis);

        return [
            'total' => (clone $query)->count(),
            'by_status' => (clone $query)->selectRaw('status, count(*) as total')
                ->groupBy('status')->pluck('total', 'status'),
            'terbaru' => (clone $query)->with('pelanggan')->latest()->take(20)->get(),
        ];
    }
}
