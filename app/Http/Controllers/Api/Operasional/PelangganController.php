<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Filters\PelangganFilter;
use App\Http\Controllers\Controller;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Repositories\Contracts\PelangganRepositoryInterface;
use App\Services\SiklusPenagihanService;
use Illuminate\Http\Request;

class PelangganController extends Controller
{
    public function __construct(
        private readonly PelangganRepositoryInterface $pelangganRepository,
        private readonly SiklusPenagihanService $siklusPenagihanService,
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
            ['layananInternet.paketInternet', 'layananInternet.tagihan', 'permohonanLayanan.paketInternet'],
        );

        return response()->json(['data' => $pelanggan]);
    }

    /**
     * Reset username & password pelanggan oleh Admin Operasional (antisipasi
     * pelanggan lupa kedua-duanya). Username dan password di-set SAMA, nilainya
     * 6 karakter acak: huruf kecil/besar + angka, tanpa karakter ambigu
     * (i I l L o O 0 1) biar aman diketik ulang.
     * Username & password baru dikembalikan sekali ini saja, lalu diserahkan
     * ke pelanggan.
     */
    public function resetUsernamePassword(Pelanggan $pelanggan)
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $nilai = '';
        $maks = strlen($alphabet) - 1;
        for ($i = 0; $i < 6; $i++) {
            $nilai .= $alphabet[random_int(0, $maks)];
        }

        $pelanggan->update([
            'username' => $nilai,
            'password' => $nilai, // auto-hash via cast 'hashed'
            'password_sudah_dibuat' => true,
        ]);

        return response()->json([
            'message' => 'Username & password pelanggan berhasil di-reset.',
            'data' => ['username' => $nilai, 'password' => $nilai],
        ]);
    }

    /**
     * Ubah masa bebas tagihan (promo gratis X bulan) per layanan. Hanya knob
     * promo yang boleh disentuh admin — tanggal_mulai_penagihan dihitung otomatis
     * oleh sistem dari tanggal_aktif + bebas bulan (Full by System).
     */
    public function aturSiklusLayanan(Request $request, LayananInternet $layanan)
    {
        $validated = $request->validate([
            'bebas_tagihan_bulan' => 'sometimes|required|integer|min:0|max:24',
        ]);

        if (! array_key_exists('bebas_tagihan_bulan', $validated)) {
            return response()->json(['message' => 'Tidak ada perubahan yang dikirim.'], 422);
        }

        $layanan->update(['bebas_tagihan_bulan' => $validated['bebas_tagihan_bulan']]);
        $layanan->loadMissing('pelanggan');

        // Jadwal mulai penagihan dihitung ulang dari tanggal_aktif + bebas bulan.
        $this->siklusPenagihanService->aturJadwalAwal($layanan);

        return response()->json([
            'message' => 'Siklus penagihan layanan berhasil diubah.',
            'data' => $layanan->fresh('pelanggan'),
        ]);
    }
}
