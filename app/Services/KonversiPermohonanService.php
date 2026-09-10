<?php

namespace App\Services;

use App\Enums\JenisPermohonanEnum;
use App\Enums\JenisPerubahanPaketEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Exceptions\TransisiStatusTidakValidException;
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
        private readonly SiklusPenagihanService $siklusPenagihanService,
    ) {}

    public function konversi(PermohonanLayanan $permohonan, ?Admin $diprosesOleh = null): LayananInternet
    {
        return DB::transaction(function () use ($permohonan, $diprosesOleh) {
            $layanan = match ($permohonan->jenis_permohonan) {
                JenisPermohonanEnum::PEMASANGAN_BARU => $this->konversiPemasanganBaru($permohonan),
                JenisPermohonanEnum::TAMBAH_PAKET => $this->konversiTambahPaket($permohonan),
                JenisPermohonanEnum::GANTI_PAKET => $this->konversiGantiPaket($permohonan, $diprosesOleh),
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

    public function konversiReseller(
        PermohonanLayanan $permohonan,
        ?Admin $diprosesOleh = null,
        ?float $hargaCustom = null,
    ): LayananInternet {
        return DB::transaction(function () use (
            $permohonan,
            $diprosesOleh,
            $hargaCustom
        ) {
            /*
            * Permohonan reseller hanya boleh diterima
            * dari status MENUNGGU_VERIFIKASI.
            */
            if ($permohonan->status !== StatusPermohonanEnum::MENUNGGU_VERIFIKASI) {
                throw new TransisiStatusTidakValidException(
                    'Permohonan reseller hanya dapat diterima dari status MENUNGGU_VERIFIKASI.'
                );
            }

            /*
            * Jika paket custom dan reseller memberikan harga baru
            * saat menerima permohonan, simpan harga tersebut
            * sebelum perubahan layanan diterapkan.
            */
            if (
                $hargaCustom !== null
                && $permohonan->tipe_paket === TipePaketEnum::CUSTOM
            ) {
                $permohonan->update([
                    'harga_custom' => $hargaCustom,
                ]);

                $permohonan->refresh();
            }

            /*
            * Reseller tidak menggunakan proses teknisi/jadwal.
            * Begitu diterima, perubahan langsung diterapkan.
            */
            $layanan = match ($permohonan->jenis_permohonan) {
                JenisPermohonanEnum::TAMBAH_PAKET =>
                    $this->konversiTambahPaket($permohonan),

                JenisPermohonanEnum::GANTI_PAKET =>
                    $this->konversiGantiPaket($permohonan, $diprosesOleh),

                JenisPermohonanEnum::RELOKASI =>
                    $this->konversiRelokasi($permohonan),

                default => throw new \InvalidArgumentException(
                    'Jenis permohonan ini tidak dapat diproses melalui alur reseller.'
                ),
            };

            /*
            * Setelah perubahan layanan berhasil diterapkan,
            * permohonan dianggap selesai.
            */
            $this->permohonanLayananService->selesaikanReseller(
                $permohonan,
                $diprosesOleh,
                'Permohonan reseller diterima dan perubahan langsung diterapkan.',
            );

            return $layanan;
        });
    }

    private function konversiPemasanganBaru(PermohonanLayanan $permohonan): LayananInternet
    {
        $this->aktivasiAkunPelangganService->aktivasiJikaLayananPertama($permohonan->pelanggan);

        $bebasBulanPromo = $permohonan->tipe_paket === TipePaketEnum::REGULER
            ? (int) ($permohonan->paketInternet?->promo_gratis_bulan ?? 0)
            : 0;

        // Buat layanan dengan status otomatis aktif dari bawaan sistem
        $layanan = $this->buatLayananDariPermohonan($permohonan, $bebasBulanPromo);

        return $layanan;
    }

    private function konversiTambahPaket(PermohonanLayanan $permohonan): LayananInternet
    {
        return $this->buatLayananDariPermohonan($permohonan);
    }

    private function konversiGantiPaket(PermohonanLayanan $permohonan, ?Admin $diprosesOleh = null): LayananInternet
    {
        $layanan = $permohonan->layananDirelokasi;

        // Ambil paket baru dari paketInternetBaru (jika diset) atau fallback ke paketInternet
        $paketBaru = $permohonan->paketInternetBaru ?? $permohonan->paketInternet;

        RiwayatPerubahanPaket::create([
            'layanan_internet_id' => $layanan->id,
            'nama_paket_lama' => $layanan->paketInternet?->nama_paket ?? $layanan->nama_paket_custom ?? '-',
            'kecepatan_lama_mbps' => $layanan->paketInternet?->kecepatan_mbps ?? $layanan->kecepatan_custom_mbps ?? 0,
            'harga_lama' => $layanan->paketInternet?->harga ?? $layanan->harga_custom ?? 0,
            'nama_paket_baru' => $paketBaru?->nama_paket ?? $permohonan->nama_paket_custom ?? '-',
            'kecepatan_baru_mbps' => $paketBaru?->kecepatan_mbps ?? $permohonan->kecepatan_custom_mbps ?? 0,
            'harga_baru' => $paketBaru?->harga ?? $permohonan->harga_custom ?? 0,
            'jenis_perubahan' => $this->tentukanJenisPerubahan($layanan, $permohonan),
            'diubah_oleh' => $diprosesOleh?->id,
            'tanggal_perubahan' => now()->toDateString(),
        ]);

        return $this->layananInternetRepository->update($layanan, [
            'paket_internet_id' => $paketBaru?->id ?? null,
            'tipe_paket' => $paketBaru ? 'reguler' : 'custom',
            'nama_paket_custom' => $paketBaru ? null : $permohonan->nama_paket_custom,
            'kecepatan_custom_mbps' => $paketBaru ? null : $permohonan->kecepatan_custom_mbps,
            'harga_custom' => $paketBaru ? null : $permohonan->harga_custom,
        ]);
    }

    private function tentukanJenisPerubahan(LayananInternet $layananLama, PermohonanLayanan $permohonan): string
    {
        $hargaLama = (float) ($layananLama->harga_custom ?? $layananLama->paketInternet?->harga ?? 0);

        $paketBaru = $permohonan->paketInternetBaru ?? $permohonan->paketInternet;
        $hargaBaru = (float) ($permohonan->harga_custom ?? $paketBaru?->harga ?? 0);

        if ($hargaBaru > $hargaLama) {
            return JenisPerubahanPaketEnum::UPGRADE->value;
        }
        if ($hargaBaru < $hargaLama) {
            return JenisPerubahanPaketEnum::DOWNGRADE->value;
        }

        return JenisPerubahanPaketEnum::UPGRADE->value;
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
            'provinsi' => $permohonan->provinsi,
            'kota' => $permohonan->kota,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
        ];

        if ($permohonan->detail_alamat) {
            $update['detail_alamat'] = $permohonan->detail_alamat;
        }

        return $this->layananInternetRepository->update($layanan, $update);
    }

    private function buatLayananDariPermohonan(PermohonanLayanan $permohonan, int $bebasBulanPromo = 0): LayananInternet
    {
        $nomorLayanan = $this->generatorNomor->generate(
            LayananInternet::class,
            'nomor_layanan',
            'LYN'
        );

        $layanan = $this->layananInternetRepository->create([
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
            'provinsi' => $permohonan->provinsi,
            'kota' => $permohonan->kota,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => now()->toDateString(),
            'bebas_tagihan_bulan' => $bebasBulanPromo,
        ]);

        // Jadwal tagihan pertama = siklus bulan depan (respect bebas_tagihan_bulan),
        // bukan langsung ditagih di bulan aktivasi.
        $this->siklusPenagihanService->aturJadwalAwal($layanan);

        return $layanan;
    }
}
