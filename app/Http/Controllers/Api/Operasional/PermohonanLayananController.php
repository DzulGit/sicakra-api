<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Filters\PermohonanLayananFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermohonanLayanan\TambahPermohonanRequest;
use App\Http\Requests\PermohonanLayanan\VerifikasiPermohonanRequest;
use App\Http\Requests\PermohonanLayanan\JadwalkanKerjaRequest;
use App\Http\Requests\PermohonanLayanan\VerifikasiDanJadwalkanRequest;
use App\Models\LayananInternet;
use App\Models\PermohonanLayanan;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use App\Services\JadwalKerjaService;
use App\Services\PermohonanLayananService;
use Illuminate\Support\Facades\DB;

class PermohonanLayananController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananRepositoryInterface $permohonanLayananRepository,
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly JadwalKerjaService $jadwalKerjaService,
        private readonly JadwalKerjaRepositoryInterface $jadwalKerjaRepository,
    ) {}

    public function index(PermohonanLayananFilter $filter)
    {
        $this->authorize('viewAny', PermohonanLayanan::class);

        return response()->json([
            'data' => $this->permohonanLayananRepository->paginate($filter),
        ]);
    }

    public function show(PermohonanLayanan $permohonan)
    {
        $this->authorize('view', $permohonan);

        $permohonan = $this->permohonanLayananRepository->find(
            $permohonan->id,
            ['pelanggan', 'paketInternet', 'riwayatStatus', 'jadwalKerja']
        );

        return response()->json(['data' => $permohonan]);
    }

    /**
     * Operasional membuat permohonan atas nama pelanggan yang SUDAH ADA
     * (tambah layanan / relokasi). Untuk pendaftaran pelanggan baru, pakai
     * endpoint publik Api\Pendaftaran\PendaftaranController.
     */
    public function store(TambahPermohonanRequest $request)
    {
        $this->authorize('create', PermohonanLayanan::class);

        $data = $request->validated();

        if ($data['jenis_permohonan'] === JenisPermohonanEnum::RELOKASI->value) {
            $layananLama = LayananInternet::findOrFail($data['layanan_internet_id']);

            // Relokasi mewarisi paket dari layanan lama — bukan pilih paket baru
            $data['tipe_paket'] = $layananLama->tipe_paket->value;
            $data['paket_internet_id'] = $layananLama->paket_internet_id;
            $data['nama_paket_custom'] = $layananLama->nama_paket_custom;
            $data['kecepatan_custom_mbps'] = $layananLama->kecepatan_custom_mbps;
            $data['harga_custom'] = $layananLama->harga_custom;
        }

        $permohonan = $this->permohonanLayananService->buatPermohonan($data);

        return response()->json(['data' => $permohonan], 201);
    }

    /**
     * Terima / Tolak / Minta Revisi.
     */
    public function verifikasi(VerifikasiPermohonanRequest $request, PermohonanLayanan $permohonan)
    {
        $this->authorize('ubahStatus', $permohonan);

        $data = $request->validated();
        $statusBaru = StatusPermohonanEnum::from($data['status']);

        $permohonan = $this->permohonanLayananService->ubahStatus(
            $permohonan,
            $statusBaru,
            $request->user(),
            $data['catatan'] ?? null,
        );

        if ($statusBaru === StatusPermohonanEnum::DITOLAK) {
            $permohonan = $this->permohonanLayananRepository->update($permohonan, [
                'alasan_ditolak' => $data['catatan'] ?? null,
            ]);
        }

        return response()->json(['data' => $permohonan]);
    }

    /**
     * Verifikasi + jadwalkan dalam satu langkah — khusus pemasangan_baru.
     * Untuk DITERIMA: ubah status + buat jadwal kerja langsung.
     * Untuk PERLU_REVISI/DITOLAK: hanya ubah status (sama seperti verifikasi biasa).
     */
    public function verifikasiDanJadwalkan(VerifikasiDanJadwalkanRequest $request, PermohonanLayanan $permohonan)
    {
        $this->authorize('ubahStatus', $permohonan);

        $data = $request->validated();
        $statusBaru = StatusPermohonanEnum::from($data['status']);

        return DB::transaction(function () use ($permohonan, $statusBaru, $data, $request) {
            $permohonan = $this->permohonanLayananService->ubahStatus(
                $permohonan,
                $statusBaru,
                $request->user(),
                $data['catatan'] ?? null,
            );

            if ($statusBaru === StatusPermohonanEnum::DITOLAK) {
                $permohonan = $this->permohonanLayananRepository->update($permohonan, [
                    'alasan_ditolak' => $data['catatan'] ?? null,
                ]);
            }

            if ($statusBaru === StatusPermohonanEnum::DITERIMA) {
                $jadwal = $this->jadwalKerjaRepository->create([
                    'permohonan_layanan_id' => $permohonan->id,
                    'tim_teknisi_id' => $data['tim_teknisi_id'] ?? null,
                    'tanggal_kerja' => $data['tanggal_kerja'],
                ]);
                $jadwal->teknisi()->sync($data['teknisi_ids']);

                $this->permohonanLayananService->ubahStatus(
                    $permohonan, StatusPermohonanEnum::DIJADWALKAN, $request->user(),
                    'Jadwal kerja dibuat langsung setelah verifikasi.'
                );

                return response()->json([
                    'data' => [
                        'permohonan' => $permohonan->fresh()->load(['pelanggan', 'paketInternet', 'riwayatStatus', 'jadwalKerja']),
                        'jadwal_kerja' => $jadwal->load(['teknisi', 'timTeknisi']),
                    ],
                ], 201);
            }

            return response()->json(['data' => $permohonan]);
        });
    }

    /**
     * Jadwalkan kunjungan teknisi — dipakai untuk penjadwalan awal (dari
     * DITERIMA) MAUPUN reschedule setelah DITUNDA. Cuma ada SATU endpoint
     * penjadwalan sekarang, gabungan survey+pemasangan.
     */
    public function jadwalkanKerja(JadwalkanKerjaRequest $request, PermohonanLayanan $permohonan)
    {
        $this->authorize('ubahStatus', $permohonan);

        $jadwal = $this->jadwalKerjaService->jadwalkan(
            $permohonan,
            $request->validated('teknisi_ids'),
            $request->validated('tanggal_kerja'),
            $request->user(),
            $request->validated('tim_teknisi_id'),
        );

        return response()->json(['data' => $jadwal], 201);
    }

    public function daftarTeknisi()
    {
        $teknisi = \App\Models\Admin::where('peran', \App\Enums\PeranAdminEnum::TEKNISI)
            ->where('status_aktif', true)
            ->select('id', 'nama_lengkap')
            ->orderBy('nama_lengkap')
            ->get();

        return response()->json(['data' => $teknisi]);
    }
}
