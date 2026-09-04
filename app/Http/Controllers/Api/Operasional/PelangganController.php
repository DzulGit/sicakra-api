<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\JenisPermohonanEnum;
use App\Filters\PelangganFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\BuatPelangganRequest;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Repositories\Contracts\PelangganRepositoryInterface;
use App\Services\AktivasiAkunPelangganService;
use App\Services\PermohonanLayananService;
use App\Services\SiklusPenagihanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PelangganController extends Controller
{
    public function __construct(
        private readonly PelangganRepositoryInterface $pelangganRepository,
        private readonly SiklusPenagihanService $siklusPenagihanService,
        private readonly AktivasiAkunPelangganService $aktivasiAkunPelangganService,
        private readonly PermohonanLayananService $permohonanLayananService,
    ) {}

    public function index(PelangganFilter $filter)
    {
        return response()->json([
            'data' => $this->pelangganRepository->paginate($filter),
        ]);
    }

    /**
     * Admin Operasional membuat pelanggan baru (pendaftaran offline/telepon).
     * Membuat pelanggan + permohonan + langsung generate kredensial (nomor_pelanggan,
     * username, password) supaya admin bisa informasikan ke pelanggan.
     *
     * Reuse logic yang sama dari PendaftaranService + AktivasiAkunPelangganService.
     */
    public function buatBaru(BuatPelangganRequest $request)
    {
        $data = $request->validated();

        $pelanggan = DB::transaction(function () use ($data, $request) {
            $pathKtp = $request->hasFile('foto_ktp')
                ? $request->file('foto_ktp')->store('ktp', 's3')
                : null;
            $pathSelfie = $request->hasFile('foto_selfie_ktp')
                ? $request->file('foto_selfie_ktp')->store('selfie-ktp', 's3')
                : null;

            $pelanggan = Pelanggan::create([
                'nama_lengkap' => $data['nama_lengkap'],
                'nik' => $data['nik'],
                'nomor_hp' => $data['nomor_hp'],
                'email' => $data['email'] ?? null,
                'foto_ktp' => $pathKtp,
                'foto_selfie_ktp' => $pathSelfie,
                'password_sudah_dibuat' => false,
            ]);

            $this->aktivasiAkunPelangganService->aktivasiJikaLayananPertama($pelanggan);

            $this->permohonanLayananService->buatPermohonan([
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
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

            return $pelanggan->fresh();
        });

        return response()->json([
            'message' => 'Pelanggan berhasil dibuat.',
            'data' => [
                'pelanggan' => $pelanggan,
                'username' => $pelanggan->username,
                'password' => $pelanggan->username, // password = nomor_pelanggan = username
            ],
        ], 201);
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
