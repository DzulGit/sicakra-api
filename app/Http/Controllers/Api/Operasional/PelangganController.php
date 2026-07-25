<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Filters\PelangganFilter;
use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Repositories\Contracts\PelangganRepositoryInterface;

class PelangganController extends Controller
{
    public function __construct(
        private readonly PelangganRepositoryInterface $pelangganRepository,
    ) {}

    public function index(PelangganFilter $filter)
    {
        return response()->json([
            'data' => $this->pelangganRepository->paginate($filter),
        ]);
    }

    public function show(Pelanggan $pelanggan)
    {
        $pelanggan = $this->pelangganRepository->find(
            $pelanggan->id,
            ['layananInternet.paketInternet', 'permohonanLayanan.paketInternet'],
        );

        return response()->json(['data' => $pelanggan]);
    }
}
