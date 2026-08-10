<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Filters\LaporanKendalaFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\LaporanKendala\TeruskanKeTeknisiRequest;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Repositories\Contracts\LaporanKendalaRepositoryInterface;
use App\Services\LaporanKendalaService;
use Illuminate\Http\Request;

class LaporanKendalaController extends Controller
{
    public function __construct(
        private readonly LaporanKendalaRepositoryInterface $laporanKendalaRepository,
        private readonly LaporanKendalaService $laporanKendalaService,
    ) {}

    public function index(LaporanKendalaFilter $filter)
    {
        $this->authorize('viewAny', LaporanKendala::class);

        return response()->json([
            'data' => $this->laporanKendalaRepository->paginateSemua($filter),
        ]);
    }

    public function show(LaporanKendala $laporanKendala)
    {
        $this->authorize('view', $laporanKendala);

        $laporan = $this->laporanKendalaRepository->find(
            $laporanKendala->id,
            ['layananInternet.pelanggan', 'ditugaskanKe', 'ditutupOleh']
        );

        return response()->json(['data' => $laporan]);
    }

    /**
     * Terima laporan (MENUNGGU -> DIPROSES). Pakai gate yang sama dengan
     * teruskanKeTeknisi karena keduanya aksi khusus Operasional/Super Admin.
     */
    public function terima(LaporanKendala $laporanKendala)
    {
        $this->authorize('teruskanKeTeknisi', $laporanKendala);

        $laporan = $this->laporanKendalaService->terima($laporanKendala);

        return response()->json(['data' => $laporan]);
    }

    public function teruskanKeTeknisi(TeruskanKeTeknisiRequest $request, LaporanKendala $laporanKendala)
    {
        $this->authorize('teruskanKeTeknisi', $laporanKendala);

        $teknisi = Admin::findOrFail($request->validated('teknisi_id'));

        $laporan = $this->laporanKendalaService->teruskanKeTeknisi($laporanKendala, $teknisi);

        return response()->json(['data' => $laporan]);
    }

    public function tutup(Request $request, LaporanKendala $laporanKendala)
    {
        $this->authorize('tutup', $laporanKendala);

        $laporan = $this->laporanKendalaService->tutup($laporanKendala, $request->user());

        return response()->json(['data' => $laporan]);
    }

    public function tindakLanjut(Request $request, LaporanKendala $laporanKendala)
    {
        $this->authorize('teruskanKeTeknisi', $laporanKendala);

        $data = $request->validate([
            'keputusan' => 'required|in:SELESAI_REMOTE,TERUSKAN_TEKNISI',
            'teknisi_ids' => 'required_if:keputusan,TERUSKAN_TEKNISI|array',
            'tanggal_kerja' => 'required_if:keputusan,TERUSKAN_TEKNISI|date',
            'hasil_penanganan' => 'required_if:keputusan,SELESAI_REMOTE|nullable|string',
        ]);

        $laporan = $this->laporanKendalaService->tindakLanjut($laporanKendala, $data, $request->user());

        return response()->json(['data' => $laporan]);
    }
}