<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\StatusLaporanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\TipePaketEnum;
use App\Enums\StatusTransaksiEnum;
use App\Enums\StatusPembayaranEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reseller\DaftarkanPelangganRequest;
use App\Services\GeneratorNomorService;
use App\Services\SiklusPenagihanService;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResellerPortalController extends Controller
{
    public function __construct(
        private readonly GeneratorNomorService $generatorNomor,
        private readonly SiklusPenagihanService $siklusPenagihanService,
    ) {}

    /** Ringkasan dashbor reseller — hanya data pelanggan miliknya. */
    public function dashboard(Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        $pelangganQuery = function () use ($reseller) {
            return Pelanggan::where('reseller_id', $reseller->id);
        };

        $aktif = $pelangganQuery()
            ->whereHas('layananInternet', fn ($q) =>
                $q->where('status', StatusLayananEnum::AKTIF)
            )
            ->count();

        $tagihanQuery = Tagihan::query()
            ->whereHas('layananInternet.pelanggan', function ($query) use ($reseller) {
                $query->where('reseller_id', $reseller->id);
            });

        $pendapatan = Pembayaran::query()
            ->where('status', StatusTransaksiEnum::BERHASIL)
            ->whereHas('tagihan.layananInternet.pelanggan', function ($query) use ($reseller) {
                $query->where('reseller_id', $reseller->id);
            })
            ->sum('jumlah_dibayar');

        $stats = [
            'total_pelanggan' => $pelangganQuery()->count(),

            'pelanggan_aktif' => $aktif,

            'kendala_aktif' => $pelangganQuery()
                ->whereHas('layananInternet.laporanKendala', fn ($q) =>
                    $q->whereIn('status', [
                        StatusLaporanEnum::MENUNGGU,
                        StatusLaporanEnum::DIPROSES,
                        StatusLaporanEnum::DITUGASKAN,
                    ])
                )
                ->count(),

            'tagihan_belum_bayar' => (clone $tagihanQuery)
                ->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR)
                ->count(),

            'pendapatan' => (float) $pendapatan,
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
            ->with([
                'layananInternet.paketInternet',
                'layananInternet.tagihan',
            ])
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
     * Reseller mendaftarkan pelanggan baru.
     *
     * Bypass alur teknisi:
     * Pelanggan -> Akun -> Layanan ACTIVE.
     */
    public function daftarkanPelanggan(DaftarkanPelangganRequest $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();
        $data = $request->validated();

        // Paket harus benar-benar milik reseller yang sedang login.
        $paket = PaketInternet::query()
            ->whereKey($data['paket_internet_id'])
            ->where('reseller_id', $reseller->id)
            ->where('status_aktif', true)
            ->firstOrFail();

        $pelangganBaru = DB::transaction(function () use (
            $data,
            $request,
            $reseller,
            $paket
        ) {
            $pathKtp = $request->hasFile('foto_ktp')
                ? Storage::disk('public')->putFile(
                    'ktp',
                    $request->file('foto_ktp')
                )
                : null;

            $pathSelfie = $request->hasFile('foto_selfie_ktp')
                ? Storage::disk('public')->putFile(
                    'selfie-ktp',
                    $request->file('foto_selfie_ktp')
                )
                : null;

            /*
            * Identitas pelanggan reseller.
            *
            * Format ini tetap dipertahankan dari implementasi sebelumnya
            * agar tidak mengubah identitas pelanggan reseller yang sudah ada.
            */
            $nomorPelanggan = 'RSL' . $reseller->id . '-' . strtoupper(
                substr(uniqid(), -6)
            );

            $pelanggan = Pelanggan::create([
                'nama_lengkap' => $data['nama_lengkap'],
                'nik' => $data['nik'],
                'nomor_hp' => $data['nomor_hp'],
                'email' => $data['email'] ?? null,
                'foto_ktp' => $pathKtp,
                'foto_selfie_ktp' => $pathSelfie,

                'reseller_id' => $reseller->id,

                // Akun langsung dibuat karena pelanggan reseller
                // tidak melewati proses aktivasi teknisi.
                'nomor_pelanggan' => $nomorPelanggan,
                'username' => $nomorPelanggan,
                'password' => $nomorPelanggan,
                'password_sudah_dibuat' => true,

                // Gunakan hari ini sebagai hari penagihan awal.
                'tanggal_tagihan' => now()->day,
            ]);

            /*
            * Reseller tidak mempunyai PermohonanLayanan.
            * Karena itu nomor layanan dibuat langsung menggunakan
            * generator nomor layanan yang sama dengan sistem utama.
            */
            $nomorLayanan = $this->generatorNomor->generate(
                LayananInternet::class,
                'nomor_layanan',
                'LYN'
            );

            $layanan = LayananInternet::create([
                'nomor_layanan' => $nomorLayanan,

                // NULL karena reseller bypass PermohonanLayanan.
                'permohonan_layanan_id' => null,

                'pelanggan_id' => $pelanggan->id,
                'paket_internet_id' => $paket->id,

                // Paket reseller tetap menggunakan tipe paket reguler
                // agar kompatibel dengan billing existing.
                'tipe_paket' => TipePaketEnum::REGULER,

                'alamat_pemasangan' => $data['alamat_pemasangan'],
                'detail_alamat' => $data['detail_alamat'] ?? null,
                'provinsi' => $data['provinsi'] ?? null,
                'kota' => $data['kota'] ?? null,
                'latitude' => $data['latitude'] ?? '0.000000',
                'longitude' => $data['longitude'] ?? '0.000000',

                'status' => StatusLayananEnum::AKTIF,
                'tanggal_aktif' => now(),
            ]);

            /*
            * Tetap gunakan mekanisme siklus penagihan existing.
            *
            * Ini hanya mengatur jadwal billing.
            * Tagihan pertama TIDAK dibuat otomatis.
            */
            $this->siklusPenagihanService->aturJadwalAwal($layanan);

            return $pelanggan->fresh([
                'layananInternet.paketInternet',
            ]);
        });

        return response()->json([
            'message' => 'Pelanggan berhasil didaftarkan dan layanan langsung aktif.',
            'data' => [
                'id' => $pelangganBaru->id,
                'nomor_pelanggan' => $pelangganBaru->nomor_pelanggan,
                'nama_lengkap' => $pelangganBaru->nama_lengkap,
                'layanan' => $pelangganBaru->layananInternet,
            ],
        ], 201);
    }
}