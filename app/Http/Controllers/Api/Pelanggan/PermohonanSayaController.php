<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermohonanLayanan\TambahPermohonanRequest;
use App\Models\PermohonanLayanan;
use App\Services\PermohonanLayananService;

class PermohonanSayaController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
    ) {}

    public function store(TambahPermohonanRequest $request)
    {
        $this->authorize('create', PermohonanLayanan::class);

        $data = $request->validated();
        $data['pelanggan_id'] = $request->user()->id;

        $permohonan = $this->permohonanLayananService->buatPermohonan($data);

        return response()->json(['data' => $permohonan], 201);
    }
}
