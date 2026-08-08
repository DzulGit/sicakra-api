<?php

namespace App\Http\Controllers\Api\Pendaftaran;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pendaftaran\SimpanPendaftaranRequest;
use App\Notifications\PendaftaranSelesaiNotification;
use App\Services\PendaftaranService;

class PendaftaranController extends Controller
{
    public function __construct(
        private readonly PendaftaranService $pendaftaranService,
    ) {}

    /**
     * Endpoint PUBLIK — dipanggil dari form Landing Page, tanpa login.
     */
    public function store(SimpanPendaftaranRequest $request)
    {
        $data = $request->validated();
        $data['foto_ktp'] = $request->file('foto_ktp');
        $data['foto_selfie_ktp'] = $request->file('foto_selfie_ktp');

        $permohonan = $this->pendaftaranService->daftar($data);

        $permohonan->pelanggan?->notify(new PendaftaranSelesaiNotification($permohonan));

        return response()->json([
            'message' => 'Pendaftaran berhasil diterima, silakan tunggu verifikasi dari tim kami.',
            'data' => [
                'nomor_permohonan' => $permohonan->nomor_permohonan,
            ],
        ], 201);
    }
}