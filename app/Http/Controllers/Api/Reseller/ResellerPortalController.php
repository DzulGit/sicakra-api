<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\JenisPermohonanEnum;
use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reseller\DaftarkanPelangganRequest;
use App\Models\Admin;
use App\Models\Pelanggan;
use App\Notifications\PelangganBaruDariResellerNotification;
use App\Services\PermohonanLayananService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Portal MITRA RESELLER — seluruh data dibatasi (scoped) pelanggan milik
 * reseller yang sedang login. Setiap query memakai relasi `Admin::pelanggan()`
 * (Pelanggan.reseller_id), jadi data pelanggan internal admin tidak pernah bocor.
 */
class ResellerPortalController extends Controller
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
    ) {}

    /** Ringkasan dashbor reseller — hanya data pelanggan miliknya. */
    public function dashboard(Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $pelangganQuery = function () use ($reseller) {
            return Pelanggan::where('reseller_id', $reseller->id);
        };

        $aktif = $pelangganQuery()->whereHas('layananInternet', fn ($q) => $q->where('status', 'aktif'))->count();

        $stats = [
            'total_pelanggan' => $pelangganQuery()->count(),
            'pelanggan_aktif' => $aktif,
            'menunggu_verifikasi' => $pelangganQuery()
                ->whereHas('permohonanLayanan', fn ($q) => $q->where('status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI))
                ->count(),
            'kendala_aktif' => $pelangganQuery()
                ->whereHas('layananInternet.laporanKendala', fn ($q) => $q->whereIn('status', [
                    StatusLaporanEnum::MENUNGGU, StatusLaporanEnum::DIPROSES, StatusLaporanEnum::DITUGASKAN,
                ]))
                ->count(),
        ];

        $terbaru = $pelangganQuery()
            ->with(['layananInternet.paketInternet'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'pelanggan_terbaru' => $terbaru,
            ],
        ]);
    }

    /** Daftar pelanggan milik reseller. */
    public function pelangganIndex(Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $pelanggan = $reseller->pelanggan()
            ->with(['layananInternet.paketInternet'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $pelanggan]);
    }

    /** Detail pelanggan — 404 bila bukan milik reseller (tidak bocor eksistensi). */
    public function pelangganShow(Request $request, Pelanggan $pelanggan)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        if ($pelanggan->reseller_id !== $reseller->id) {
            abort(404);
        }

        $pelanggan->load([
            'layananInternet.paketInternet',
            'layananInternet.tagihan',
            'permohonanLayanan.paketInternet',
        ]);

        return response()->json(['data' => $pelanggan]);
    }

    /**
     * Reseller mendaftarkan pelanggan baru. Mirip alur Admin Operasional
     * (buat pelanggan + permohonan pemasangan_baru MENUNGGU_VERIFIKASI), tapi
     * reseller_id dipaksa milik si reseller & notifikasi dikirim ke admin
     * operasional/super admin untuk verifikasi.
     */
    public function daftarkanPelanggan(DaftarkanPelangganRequest $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();
        $data = $request->validated();

        $permohonan = DB::transaction(function () use ($data, $request, $reseller) {
            $pathKtp = $request->hasFile('foto_ktp')
                ? Storage::disk('public')->putFile('ktp', $request->file('foto_ktp'))
                : null;
            $pathSelfie = $request->hasFile('foto_selfie_ktp')
                ? Storage::disk('public')->putFile('selfie-ktp', $request->file('foto_selfie_ktp'))
                : null;

            $pelanggan = Pelanggan::create([
                'nama_lengkap' => $data['nama_lengkap'],
                'nik' => $data['nik'],
                'nomor_hp' => $data['nomor_hp'],
                'email' => $data['email'],
                'foto_ktp' => $pathKtp,
                'foto_selfie_ktp' => $pathSelfie,
                'password_sudah_dibuat' => false,
                'reseller_id' => $reseller->id,
            ]);

            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new PelangganBaruDariResellerNotification($pelanggan, $reseller),
            );

            return $this->permohonanLayananService->buatPermohonan([
                'pelanggan_id' => $pelanggan->id,
                'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
                'paket_internet_id' => $data['paket_internet_id'] ?? null,
                'tipe_paket' => $data['tipe_paket'],
                'nama_paket_custom' => $data['nama_paket_custom'] ?? null,
                'kecepatan_custom_mbps' => $data['kecepatan_custom_mbps'] ?? null,
                'catatan_custom' => $data['catatan_custom'] ?? null,
                'alamat_pemasangan' => $data['alamat_pemasangan'],
                'detail_alamat' => $data['detail_alamat'] ?? null,
                'provinsi' => $data['provinsi'] ?? null,
                'kota' => $data['kota'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Pelanggan berhasil didaftarkan. Menunggu verifikasi operasional.',
            'data' => [
                'id' => $permohonan->id,
                'nomor_permohonan' => $permohonan->nomor_permohonan,
                'nama_lengkap' => $permohonan->pelanggan->nama_lengkap,
            ],
        ], 201);
    }
}
