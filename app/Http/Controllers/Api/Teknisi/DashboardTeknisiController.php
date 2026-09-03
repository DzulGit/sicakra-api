<?php

namespace App\Http\Controllers\Api\Teknisi;

use App\Enums\HasilKerjaEnum;
use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusLaporanEnum;
use App\Http\Controllers\Controller;
use App\Models\JadwalKerja;
use App\Models\LaporanKendala;
use Illuminate\Http\Request;
use App\Models\PermohonanLayanan;
use App\Enums\StatusPermohonanEnum;
use App\Services\PermohonanLayananService;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use Illuminate\Support\Facades\DB;

class DashboardTeknisiController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly PermohonanLayananRepositoryInterface $permohonanLayananRepository
    ) {}

    public function index(Request $request)
    {
        $teknisiId = $request->user()->id;
        $hariIni = now()->toDateString();

        $milikTeknisi = fn ($query) => $query->whereHas('teknisi', fn ($q) => $q->where('admin_id', $teknisiId));

        $jadwalHariIni = JadwalKerja::where('tanggal_kerja', $hariIni);
        $belumSelesai = JadwalKerja::whereNull('hasil');
        $selesaiHariIni = JadwalKerja::where('hasil', HasilKerjaEnum::SELESAI)->whereDate('updated_at', $hariIni);
        $tiketAktif = LaporanKendala::where('ditugaskan_ke', $teknisiId)->whereIn('status', [
            StatusLaporanEnum::DITUGASKAN,
        ]);

        $stats = [
            'pekerjaan_hari_ini' => $milikTeknisi((clone $jadwalHariIni))->count(),
            'sedang_dikerjakan' => $milikTeknisi((clone $belumSelesai))->count(),
            'selesai_hari_ini' => $milikTeknisi((clone $selesaiHariIni))->count(),
            'tiket_kendala_aktif' => $tiketAktif->count(),
        ];

        $ringkas = fn (JadwalKerja $item) => [
            'id' => $item->id,
            'nomor_permohonan' => $item->permohonanLayanan?->nomor_permohonan,
            'pelanggan' => $item->permohonanLayanan?->pelanggan?->nama_lengkap,
            'jenis_pekerjaan' => $item->permohonanLayanan?->jenis_permohonan instanceof JenisPermohonanEnum
                ? $item->permohonanLayanan->jenis_permohonan->label()
                : $item->permohonanLayanan?->jenis_permohonan,
            'alamat' => $item->permohonanLayanan?->alamat_pemasangan,
            'waktu' => $item->tanggal_kerja?->format('d M Y'),
            'status' => $item->hasil?->value ?? 'belum',
        ];

        $jadwalHariIniList = $milikTeknisi((clone $jadwalHariIni))
            ->with('permohonanLayanan.pelanggan')
            ->orderBy('tanggal_kerja')
            ->get();

        $riwayat = JadwalKerja::whereNotNull('hasil')
            ->whereHas('teknisi', fn ($q) => $q->where('admin_id', $teknisiId))
            ->with('permohonanLayanan.pelanggan')
            ->latest('updated_at')
            ->take(10)
            ->get();

        $tiketKendalaAktif = $tiketAktif
            ->with('layananInternet.pelanggan')
            ->latest()
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'jadwal_hari_ini' => $jadwalHariIniList->map($ringkas)->values(),
                'tiket_kendala_aktif' => $tiketKendalaAktif,
                'riwayat_pekerjaan' => $riwayat->map($ringkas)->values(),
            ],
        ]);
    }

    public function antreanPengecekan(Request $request)
    {
        $permohonan = PermohonanLayanan::with(['pelanggan', 'paketInternet'])
            ->where('status', StatusPermohonanEnum::MENUNGGU_PENGECEKAN_TEKNIS)
            ->paginate($request->per_page ?? 10);

        return response()->json(['data' => $permohonan]);
    }

    public function layakPasang(Request $request, PermohonanLayanan $permohonan)
    {
        if ($permohonan->status !== StatusPermohonanEnum::MENUNGGU_PENGECEKAN_TEKNIS) {
            abort(400, 'Status permohonan tidak valid.');
        }

        $permohonan = $this->permohonanLayananService->ubahStatus(
            $permohonan,
            StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
            $request->user(),
            'Lokasi terjangkau. Menunggu Admin Operasional mengatur jadwal pemasangan.'
        );

        return response()->json(['data' => $permohonan]);
    }

    public function tolak(Request $request, PermohonanLayanan $permohonan)
    {
        $request->validate(['catatan' => 'required|string|max:255']);

        if ($permohonan->status !== StatusPermohonanEnum::MENUNGGU_PENGECEKAN_TEKNIS) {
            abort(400, 'Status permohonan tidak valid.');
        }

        DB::transaction(function () use ($permohonan, $request) {
            $permohonan = $this->permohonanLayananService->ubahStatus(
                $permohonan,
                StatusPermohonanEnum::DITOLAK,
                $request->user(),
                $request->catatan
            );

            $this->permohonanLayananRepository->update($permohonan, [
                'alasan_ditolak' => $request->catatan,
            ]);

            $permohonan->pelanggan?->notify(new PermohonanStatusNotification(
                $permohonan,
                StatusPermohonanEnum::DITOLAK,
                $request->catatan
            ));
        });

        return response()->json(['message' => 'Permohonan ditolak']);
    }
}
