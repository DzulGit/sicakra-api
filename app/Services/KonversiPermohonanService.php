<?php

namespace App\Services;

use App\Enums\JenisPerubahanPaketEnum;
use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PermohonanLayanan;
use App\Models\RiwayatPerubahanPaket;
use App\Models\RiwayatRelokasi;
use App\Repositories\Contracts\LayananInternetRepositoryInterface;
use Illuminate\Support\Facades\DB;

class KonversiPermohonanService
{
    public function __construct(
        private readonly LayananInternetRepositoryInterface $layananInternetRepository,
        private readonly GeneratorNomorService $generatorNomor,
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly AktivasiAkunPelangganService $aktivasiAkunPelangganService,
    ) {}

    public function konversi(PermohonanLayanan $permohonan, ?Admin $diprosesOleh = null): LayananInternet
    {
        return DB::transaction(function () use ($permohonan, $diprosesOleh) {
            $layanan = match ($permohonan->jenis_permohonan) {
                JenisPermohonanEnum::PEMASANGAN_BARU => $this->konversiPemasanganBaru($permohonan),
                JenisPermohonanEnum::TAMBAH_PAKET => $this->konversiTambahPaket($permohonan),
                JenisPermohonanEnum::GANTI_PAKET => $this->konversiGantiPaket($permohonan),
                JenisPermohonanEnum::RELOKASI => $this->konversiRelokasi($permohonan),
            };

            $this->permohonanLayananService->ubahStatus(
                $permohonan,
                StatusPermohonanEnum::DIKONVERSI,
                $diprosesOleh,
                'Permohonan selesai, dikonversi.'
            );

            return $layanan;
        });
    }

    private function konversiPemasanganBaru(PermohonanLayanan $permohonan): LayananInternet
    {
        $layanan = $this->buatLayananDariPermohonan($permohonan);
        $this->aktivasiAkunPelangganService->aktivasiJikaLayananPertama($permohonan->pelanggan);
        return $layanan;
    }

    private function konversiTambahPaket(PermohonanLayanan $permohonan): LayananInternet
    {
        return $this->buatLayananDariPermohonan($permohonan);
    }

    private function konversiGantiPaket(PermohonanLayanan $permohonan): LayananInternet
    {
        $layanan = $permohonan->layananDirelokasi;
        $paketBaru = $permohonan->paketInternetBaru;

        RiwayatPerubahanPaket::create([
            'layanan_internet_id' => $layanan->id,
            'nama_paket_lama' => $layanan->paket_internet?->nama_paket ?? $layanan->nama_paket_custom ?? '-',
            'kecepatan_lama_mbps' => $layanan->kecepatan_custom_mbps,
            'harga_lama' => $layanan->harga_custom,
            'nama_paket_baru' => $paketBaru?->nama_paket ?? '-',
            'kecepatan_baru_mbps' => $paketBaru?->kecepatan_mbps ?? $permohonan->kecepatan_custom_mbps,
            'harga_baru' => $paketBaru?->harga ?? $permohonan->harga_custom,
            'jenis_perubahan' => $this->tentukanJenisPerubahan($layanan, $permohonan),
            'diubah_oleh' => null,
            'tanggal_perubahan' => now()->toDateString(),
        ]);

        return $this->layananInternetRepository->update($layanan, [
            'paket_internet_id' => $permohonan->paket_internet_id_baru ?? $layanan->paket_internet_id,
            'tipe_paket' => 'reguler',
            'nama_paket_custom' => null,
            'kecepatan_custom_mbps' => $paketBaru?->kecepatan_mbps,
            'harga_custom' => $paketBaru?->harga,
        ]);
    }

    private function konversiRelokasi(PermohonanLayanan $permohonan): LayananInternet
    {
        $layanan = $permohonan->layananDirelokasi;

        RiwayatRelokasi::create([
            'layanan_internet_id' => $layanan->id,
            'permohonan_layanan_id' => $permohonan->id,
            'alamat_lama' => $layanan->alamat_pemasangan,
            'latitude_lama' => $layanan->latitude,
            'longitude_lama' => $layanan->longitude,
            'alamat_baru' => $permohonan->alamat_pemasangan,
            'latitude_baru' => $permohonan->latitude,
            'longitude_baru' => $permohonan->longitude,
            'tanggal_relokasi' => now()->toDateString(),
        ]);

        $update = [
            'alamat_pemasangan' => $permohonan->alamat_pemasangan,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
        ];
        
        if ($permohonan->detail_alamat) {
            $update['detail_alamat'] = $permohonan->detail_alamat;
        }

        return $this->layananInternetRepository->update($layanan, $update);
    }

    private function buatLayananDariPermohonan(PermohonanLayanan $permohonan): LayananInternet
    {
        $nomorLayanan = $this->generatorNomor->generate(
            LayananInternet::class,
            'nomor_layanan',
            'LYN'
        );

        return $this->layananInternetRepository->create([
            'nomor_layanan' => $nomorLayanan,
            'permohonan_layanan_id' => $permohonan->id,
            'pelanggan_id' => $permohonan->pelanggan_id,
            'paket_internet_id' => $permohonan->paket_internet_id,
            'tipe_paket' => $permohonan->tipe_paket,
            'nama_paket_custom' => $permohonan->nama_paket_custom,
            'kecepatan_custom_mbps' => $permohonan->kecepatan_custom_mbps,
            'harga_custom' => $permohonan->harga_custom,
            'alamat_pemasangan' => $permohonan->alamat_pemasangan,
            'detail_alamat' => $permohonan->detail_alamat,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => now()->toDateString(),
        ]);
    }

    private function tentukanJenisPerubahan(LayananInternet $layananLama, PermohonanLayanan $permohonan): string
    {
        $hargaLama = (float) ($layananLama->harga_custom ?? $layananLama->paket_internet?->harga ?? 0);
        $hargaBaru = (float) ($permohonan->harga_custom ?? $permohonan->paketInternetBaru?->harga ?? 0);

        if ($hargaBaru > $hargaLama) return JenisPerubahanPaketEnum::UPGRADE->value;
        if ($hargaBaru < $hargaLama) return JenisPerubahanPaketEnum::DOWNGRADE->value;

        return JenisPerubahanPaketEnum::UPGRADE->value;
    }
}
