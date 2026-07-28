<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Filters\LaporanKendalaFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\LaporanKendala\BuatLaporanRequest;
use App\Models\LaporanKendala;
use App\Repositories\Contracts\LaporanKendalaRepositoryInterface;
use App\Services\LaporanKendalaService;
use Illuminate\Http\Request;

class LaporanKendalaSayaController extends Controller
{
    public function __construct(
        private readonly LaporanKendalaRepositoryInterface $laporanKendalaRepository,
        private readonly LaporanKendalaService $laporanKendalaService,
    ) {}

    public function index(Request $request, LaporanKendalaFilter $filter)
    {
        $this->authorize('viewAny', LaporanKendala::class);

        return response()->json([
            'data' => $this->laporanKendalaRepository->paginateUntukPelanggan($request->user()->id, $filter),
        ]);
    }

    public function show(LaporanKendala $laporanKendala)
    {
        $this->authorize('view', $laporanKendala);

        $laporan = $this->laporanKendalaRepository->find($laporanKendala->id, ['layananInternet']);

        return response()->json(['data' => $laporan]);
    }

    public function store(BuatLaporanRequest $request)
    {            
        $this->authorize('create', LaporanKendala::class);

        $data = $request->validated();

        // validated() tidak otomatis menyertakan file upload — ambil
        // eksplisit lewat file() kalau pelanggan memang mengunggah foto.
        if ($request->hasFile('foto')) {
            $data['foto'] = $request->file('foto');
        }

        $laporan = $this->laporanKendalaService->buat($data, $request->user());

        return response()->json(['data' => $laporan], 201);
    }
}