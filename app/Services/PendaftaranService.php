<?php

namespace App\Services;

use App\Enums\JenisPermohonanEnum;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PendaftaranService
{
    public function __construct(
        private readonly PermohonanLayananService $permohonanLayananService,
    ) {}

    /**
     * Alur pendaftaran dari Landing Page (PUBLIK, belum ada akun).
     * Membuat baris pelanggan BARU + permohonan_layanan (jenis: pemasangan_baru).
     *
     * Catatan: untuk pelanggan LAMA yang mau tambah layanan/relokasi, pakai
     * Api\Operasional\PermohonanLayananController@store (perlu login admin).
     */
    public function daftar(array $data): PermohonanLayanan
    {
        return DB::transaction(function () use ($data) {
            $pathKtp = $this->simpanFoto($data['foto_ktp'], 'ktp');
            $pathSelfie = isset($data['foto_selfie_ktp']) ? $this->simpanFoto($data['foto_selfie_ktp'], 'selfie-ktp') : null;

            $pelanggan = Pelanggan::create([
                'nama_lengkap' => $data['nama_lengkap'],
                'nik' => $data['nik'],
                'nomor_hp' => $data['nomor_hp'],
                'email' => $data['email'] ?? null,
                'foto_ktp' => $pathKtp,
                'foto_selfie_ktp' => $pathSelfie,
                'password_sudah_dibuat' => false,
            ]);

            return $this->permohonanLayananService->buatPermohonan([
                'pelanggan_id' => $pelanggan->id,
                'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
                'paket_internet_id' => $data['paket_internet_id'] ?? null,
                'tipe_paket' => $data['tipe_paket'],
                'nama_paket_custom' => $data['nama_paket_custom'] ?? null,
                'kecepatan_custom_mbps' => $data['kecepatan_custom_mbps'] ?? null,
                // harga_custom SENGAJA tidak diisi di sini — baru ditentukan
                // Operasional saat negosiasi paket custom.
                'catatan_custom' => $data['catatan_custom'] ?? null,
                'alamat_pemasangan' => $data['alamat_pemasangan'],
                'detail_alamat' => $data['detail_alamat'] ?? null,
                'provinsi' => $data['provinsi'] ?? null,
                'kota' => $data['kota'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);
        });
    }

    private function simpanFoto(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, 's3');
    }
}
