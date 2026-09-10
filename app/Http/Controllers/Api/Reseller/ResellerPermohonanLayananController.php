<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Filters\PermohonanLayananFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermohonanLayanan\VerifikasiPermohonanRequest;
use App\Http\Requests\Reseller\BuatPermohonanResellerRequest;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use App\Notifications\PermohonanStatusNotification;
use App\Services\KonversiPermohonanService;
use App\Services\PermohonanLayananService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ResellerPermohonanLayananController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly KonversiPermohonanService $konversiPermohonanService,
    ) {}

    private function pastikanMilikReseller(
        PermohonanLayanan $permohonan,
        Admin $reseller
    ): void {
        if ($permohonan->pelanggan?->reseller_id !== $reseller->id) {
            abort(404);
        }
    }

    public function index(PermohonanLayananFilter $filter, Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $query = PermohonanLayanan::query()
            ->whereHas(
                'pelanggan',
                fn (Builder $q) => $q->where('reseller_id', $reseller->id)
            )
            ->with('pelanggan')
            ->latest();

        return response()->json([
            'data' => $filter->apply($query)->paginate(20),
        ]);
    }

    public function show(Request $request, PermohonanLayanan $permohonan)
    {
        $this->pastikanMilikReseller($permohonan, $request->user());

        $permohonan->load([
            'pelanggan',
            'paketInternet',
            'paketInternetBaru',
            'layananDirelokasi',
            'riwayatStatus.diubahOleh',
            'jadwalKerja',
        ]);

        return response()->json([
            'data' => $permohonan,
        ]);
    }

    /**
     * Reseller membuat permohonan atas nama pelanggan yang SUDAH ADA.
     *
     * Jenis:
     * - relokasi
     * - ganti paket
     * - tambah paket
     */
    public function store(BuatPermohonanResellerRequest $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $data = $request->validated();

        $pelanggan = Pelanggan::whereKey($data['pelanggan_id'])
            ->where('reseller_id', $reseller->id)
            ->firstOrFail();

        $layananLama = isset($data['layanan_internet_id'])
            ? LayananInternet::whereKey($data['layanan_internet_id'])
                ->where('pelanggan_id', $pelanggan->id)
                ->firstOrFail()
            : null;

        if ($data['jenis_permohonan'] === JenisPermohonanEnum::RELOKASI->value) {
            $data['tipe_paket'] = $layananLama->tipe_paket->value;
            $data['paket_internet_id'] = $layananLama->paket_internet_id;
            $data['nama_paket_custom'] = $layananLama->nama_paket_custom;
            $data['kecepatan_custom_mbps'] = $layananLama->kecepatan_custom_mbps;
            $data['harga_custom'] = $layananLama->harga_custom;
        }

        if ($data['jenis_permohonan'] === JenisPermohonanEnum::GANTI_PAKET->value) {
            $data['paket_internet_id_baru'] = $data['paket_internet_id'] ?? null;
            $data['paket_internet_id'] = $layananLama->paket_internet_id;
        }

        $permohonan = $this->permohonanLayananService->buatPermohonan($data);

        return response()->json([
            'data' => $permohonan,
        ], 201);
    }

    /**
     * Terima / Tolak / Minta Revisi.
     *
     * Untuk reseller:
     *
     * TERIMA
     * -> perubahan layanan langsung diterapkan
     * -> status menjadi DIKONVERSI
     *
     * TOLAK
     * -> tidak mengubah layanan
     * -> status menjadi DITOLAK
     *
     * PERLU_REVISI
     * -> tidak mengubah layanan
     * -> status menjadi PERLU_REVISI
     */
    public function verifikasi(
        VerifikasiPermohonanRequest $request,
        PermohonanLayanan $permohonan
    ) {
        $this->pastikanMilikReseller(
            $permohonan,
            $request->user()
        );

        $data = $request->validated();

        $statusBaru = StatusPermohonanEnum::from(
            $data['status']
        );

        /*
         * TERIMA
         *
         * Perubahan LayananInternet langsung diterapkan
         * oleh KonversiPermohonanService.
         */
        if ($statusBaru === StatusPermohonanEnum::DITERIMA) {
            $this->konversiPermohonanService->konversiReseller(
                $permohonan,
                $request->user(),
                $data['harga_custom'] ?? null,
            );

            $permohonan = $permohonan->fresh([
                'pelanggan',
                'paketInternet',
                'paketInternetBaru',
                'layananDirelokasi',
                'riwayatStatus.diubahOleh',
                'jadwalKerja',
            ]);

            $permohonan->pelanggan?->notify(
                new PermohonanStatusNotification(
                    $permohonan,
                    StatusPermohonanEnum::DIKONVERSI,
                    $data['catatan']
                        ?? 'Permohonan diterima dan perubahan langsung diterapkan.',
                )
            );

            return response()->json([
                'data' => $permohonan,
            ]);
        }

        /*
         * TOLAK / PERLU REVISI
         *
         * Tidak ada perubahan pada LayananInternet.
         */
        $permohonan = $this->permohonanLayananService->ubahStatus(
            $permohonan,
            $statusBaru,
            $request->user(),
            $data['catatan'] ?? null,
        );

        if ($statusBaru === StatusPermohonanEnum::DITOLAK) {
            $permohonan->update([
                'alasan_ditolak' => $data['catatan'] ?? null,
            ]);
        }

        $permohonan->pelanggan?->notify(
            new PermohonanStatusNotification(
                $permohonan,
                $statusBaru,
                $data['catatan'] ?? null,
            )
        );

        return response()->json([
            'data' => $permohonan->fresh([
                'pelanggan',
                'paketInternet',
                'paketInternetBaru',
                'layananDirelokasi',
                'riwayatStatus.diubahOleh',
                'jadwalKerja',
            ]),
        ]);
    }
}