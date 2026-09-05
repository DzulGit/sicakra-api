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
use Illuminate\Support\Facades\Storage;

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
                ? Storage::disk('public')->putFile('ktp', $request->file('foto_ktp'))
                : null;
            $pathSelfie = $request->hasFile('foto_selfie_ktp')
                ? Storage::disk('public')->putFile('selfie-ktp', $request->file('foto_selfie_ktp'))
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

    public function aturTanggalTagihan(Request $request, Pelanggan $pelanggan)
    {
        $validated = $request->validate([
            'tanggal_tagihan' => 'required|integer|min:1|max:31',
        ]);

        $pelanggan->update(['tanggal_tagihan' => $validated['tanggal_tagihan']]);

        return response()->json([
            'message' => 'Tanggal penagihan pelanggan berhasil diubah.',
            'data' => $pelanggan->fresh(),
        ]);
    }

    /**
     * "Terapkan untuk Semua" — set tanggal_tagihan massal. Kalau `pelanggan_ids`
     * dikirim, hanya pelanggan yang terpilih yang diubah; kosongkan untuk semua
     * pelanggan aktif (yang sudah punya nomor_pelanggan).
     */
    public function bulkAturTanggalTagihan(Request $request)
    {
        $validated = $request->validate([
            'tanggal_tagihan' => 'required|integer|min:1|max:31',
            'pelanggan_ids' => 'sometimes|array',
            'pelanggan_ids.*' => 'integer|exists:pelanggan,id',
        ]);

        $query = Pelanggan::query();

        if (! empty($validated['pelanggan_ids'])) {
            $query->whereIn('id', $validated['pelanggan_ids']);
        } else {
            $query->whereNotNull('nomor_pelanggan');
        }

        $jumlah = $query->update(['tanggal_tagihan' => $validated['tanggal_tagihan']]);

        return response()->json([
            'message' => "Tanggal penagihan diterapkan ke {$jumlah} pelanggan.",
            'data' => ['ter_update' => $jumlah],
        ]);
    }

    /**
     * Override siklus penagihan per layanan: masa bebas tagihan &/atau tanggal
     * mulai penagihan. Admin (Keuangan/Super Admin) yang memegang kendali promo.
     */
    public function aturSiklusLayanan(Request $request, LayananInternet $layanan)
    {
        $validated = $request->validate([
            'bebas_tagihan_bulan' => 'sometimes|required|integer|min:0|max:24',
            'tanggal_mulai_penagihan' => 'sometimes|required|date',
        ]);

        $adaPerubahanBulanBebas = array_key_exists('bebas_tagihan_bulan', $validated);
        $adaTanggalManual = array_key_exists('tanggal_mulai_penagihan', $validated);

        $data = [];
        if ($adaPerubahanBulanBebas) {
            $data['bebas_tagihan_bulan'] = $validated['bebas_tagihan_bulan'];
        }
        if ($adaTanggalManual) {
            $data['tanggal_mulai_penagihan'] = $validated['tanggal_mulai_penagihan'];
        }

        $layanan->update($data);
        $layanan->loadMissing('pelanggan');

        // Kalau admin cuma ubah masa bebas, jadwal mulai penagihan ikut
        // dihitung ulang dari tanggal_aktif (kecuali tanggal manual diberikan).
        if ($adaPerubahanBulanBebas && ! $adaTanggalManual) {
            $this->siklusPenagihanService->aturJadwalAwal($layanan);
        }

        return response()->json([
            'message' => 'Siklus penagihan layanan berhasil diubah.',
            'data' => $layanan->fresh('pelanggan'),
        ]);
    }
}
