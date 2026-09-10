<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Filters\PermohonanLayananFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\PermohonanLayanan\VerifikasiPermohonanRequest;
use App\Http\Requests\Reseller\BuatPermohonanResellerRequest;
use App\Http\Requests\Reseller\JadwalkanResellerRequest;
use App\Http\Requests\Reseller\VerifikasiDanJadwalkanResellerRequest;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use App\Notifications\PermohonanStatusNotification;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use App\Services\JadwalKerjaService;
use App\Services\PermohonanLayananService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResellerPermohonanLayananController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly JadwalKerjaService $jadwalKerjaService,
        private readonly JadwalKerjaRepositoryInterface $jadwalKerjaRepository,
    ) {}

    private function pastikanMilikReseller(PermohonanLayanan $permohonan, Admin $reseller): void
    {
        if ($permohonan->pelanggan?->reseller_id !== $reseller->id) {
            abort(404);
        }
    }

    public function index(PermohonanLayananFilter $filter, Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $query = PermohonanLayanan::query()
            ->whereHas('pelanggan', fn (Builder $q) => $q->where('reseller_id', $reseller->id))
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

        return response()->json(['data' => $permohonan]);
    }

    /**
     * Reseller membuat permohonan atas nama pelanggan yang SUDAH ADA
     * (relokasi / ganti paket / tambah paket). Pemasangan baru bypass.
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

        return response()->json(['data' => $permohonan], 201);
    }

    /** Terima / Tolak / Minta Revisi. */
    public function verifikasi(VerifikasiPermohonanRequest $request, PermohonanLayanan $permohonan)
    {
        $this->pastikanMilikReseller($permohonan, $request->user());

        $data = $request->validated();
        $statusBaru = StatusPermohonanEnum::from($data['status']);

        $permohonan = $this->permohonanLayananService->ubahStatus(
            $permohonan,
            $statusBaru,
            $request->user(),
            $data['catatan'] ?? null,
        );

        if ($statusBaru === StatusPermohonanEnum::DITERIMA
            && $permohonan->tipe_paket->value === TipePaketEnum::CUSTOM->value
            && isset($data['harga_custom'])) {
            $permohonan->update(['harga_custom' => $data['harga_custom']]);
        }

        if ($statusBaru === StatusPermohonanEnum::DITOLAK) {
            $permohonan->update(['alasan_ditolak' => $data['catatan'] ?? null]);
        }

        $permohonan->pelanggan?->notify(new PermohonanStatusNotification(
            $permohonan,
            $statusBaru,
            $data['catatan'] ?? null,
        ));

        return response()->json(['data' => $permohonan->fresh([
            'pelanggan',
            'paketInternet',
            'paketInternetBaru',
            'layananDirelokasi',
            'riwayatStatus.diubahOleh',
            'jadwalKerja',
        ])]);
    }

    /** Verifikasi + jadwalkan dalam satu langkah — tanpa teknisi. */
    public function verifikasiDanJadwalkan(VerifikasiDanJadwalkanResellerRequest $request, PermohonanLayanan $permohonan)
    {
        $this->pastikanMilikReseller($permohonan, $request->user());

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
                $permohonan->update(['alasan_ditolak' => $data['catatan'] ?? null]);
            }

            if ($statusBaru === StatusPermohonanEnum::DITERIMA) {
                if ($permohonan->tipe_paket->value === TipePaketEnum::CUSTOM->value && isset($data['harga_custom'])) {
                    $permohonan->update(['harga_custom' => $data['harga_custom']]);
                }

                $jadwal = $this->jadwalKerjaRepository->create([
                    'permohonan_layanan_id' => $permohonan->id,
                    'tim_teknisi_id' => null,
                    'tanggal_kerja' => $data['tanggal_kerja'],
                ]);
                $jadwal->teknisi()->sync([]);

                $this->permohonanLayananService->ubahStatus(
                    $permohonan,
                    StatusPermohonanEnum::DIJADWALKAN,
                    $request->user(),
                    'Jadwal kerja dibuat langsung setelah verifikasi.',
                );

                $permohonan->pelanggan?->notify(new PermohonanStatusNotification(
                    $permohonan,
                    StatusPermohonanEnum::DIJADWALKAN,
                    $data['catatan'] ?? null,
                ));

                return response()->json([
                    'data' => [
                        'permohonan' => $permohonan->fresh()->load([
                            'pelanggan',
                            'paketInternet',
                            'paketInternetBaru',
                            'layananDirelokasi',
                            'riwayatStatus.diubahOleh',
                            'jadwalKerja',
                        ]),
                        'jadwal_kerja' => $jadwal->load(['teknisi', 'timTeknisi']),
                    ],
                ], 201);
            }

            $permohonan->pelanggan?->notify(new PermohonanStatusNotification(
                $permohonan,
                $statusBaru,
                $data['catatan'] ?? null,
            ));

            return response()->json([
                'data' => $permohonan->fresh()->load([
                    'pelanggan',
                    'paketInternet',
                    'paketInternetBaru',
                    'layananDirelokasi',
                    'riwayatStatus.diubahOleh',
                    'jadwalKerja',
                ]),
            ]);
        });
    }

    /** Jadwalkan ulang (mis. setelah DITUNDA) — tanpa teknisi. */
    public function jadwalkanKerja(JadwalkanResellerRequest $request, PermohonanLayanan $permohonan)
    {
        $this->pastikanMilikReseller($permohonan, $request->user());

        $jadwal = $this->jadwalKerjaService->jadwalkan(
            $permohonan,
            [],
            $request->validated('tanggal_kerja'),
            $request->user(),
        );

        $permohonan->pelanggan?->notify(new PermohonanStatusNotification(
            $permohonan,
            StatusPermohonanEnum::DIJADWALKAN,
            'Jadwal kunjungan: '.now()->parse($request->validated('tanggal_kerja'))->format('d M Y'),
        ));

        return response()->json(['data' => $jadwal], 201);
    }
}
