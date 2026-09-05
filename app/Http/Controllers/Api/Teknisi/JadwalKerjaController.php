<?php

namespace App\Http\Controllers\Api\Teknisi;

use App\Enums\HasilKerjaEnum;
use App\Filters\JadwalKerjaFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermohonanLayanan\HasilKerjaRequest;
use App\Models\JadwalKerja;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use App\Services\JadwalKerjaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JadwalKerjaController extends Controller
{
    public function __construct(
        private readonly JadwalKerjaRepositoryInterface $jadwalKerjaRepository,
        private readonly JadwalKerjaService $jadwalKerjaService,
    ) {}

    public function index(Request $request, JadwalKerjaFilter $filter)
    {
        return response()->json([
            'data' => $this->jadwalKerjaRepository->paginateMilikTeknisi($request->user()->id, $filter),
        ]);
    }

    public function show(Request $request, JadwalKerja $jadwalKerja)
    {
        $this->authorize('view', $jadwalKerja);

        $jadwal = $this->jadwalKerjaRepository->find(
            $jadwalKerja->id,
            ['permohonanLayanan.pelanggan', 'permohonanLayanan.paketInternet', 'teknisi', 'timTeknisi']
        );

        return response()->json(['data' => $jadwal]);
    }

    /** Response menyertakan `ringkasan_aktivasi` (username dkk) kalau hasil = selesai. */
    public function isiHasil(HasilKerjaRequest $request, JadwalKerja $jadwalKerja)
    {
        $this->authorize('isiHasil', $jadwalKerja);

        $data = $request->validated();

        $fotoDokumentasi = [];
        foreach ($request->file('foto_dokumentasi', []) as $file) {
            $fotoDokumentasi[] = Storage::disk('public')->putFile('dokumentasi-pekerjaan', $file);
        }

        $hasil = $this->jadwalKerjaService->isiHasil(
            $jadwalKerja,
            HasilKerjaEnum::from($data['hasil']),
            $data['catatan_kendala'] ?? null,
            $request->user(),
            $fotoDokumentasi,
            isset($data['latitude_hasil']) ? (float) $data['latitude_hasil'] : null,
            isset($data['longitude_hasil']) ? (float) $data['longitude_hasil'] : null,
        );

        return response()->json(['data' => $hasil]);
    }
}
