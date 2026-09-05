<?php

namespace App\Services;

use App\Enums\StatusPermohonanEnum;
use App\Exceptions\TransisiStatusTidakValidException;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PermohonanLayanan;
use App\Models\RiwayatStatusPermohonan;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use Illuminate\Support\Facades\DB;

class PermohonanLayananService
{
    public function __construct(
        private readonly PermohonanLayananRepositoryInterface $permohonanLayananRepository,
        private readonly GeneratorNomorService $generatorNomor,
    ) {}

    /**
     * Buat permohonan baru (pemasangan_baru ATAU relokasi).
     * Status awal MENUNGGU_PENGECEKAN_TEKNIS — teknisi mengecek kelayakan
     * jaringan dulu, lalu Operasional/Teknisi memindahkan ke MENUNGGU_VERIFIKASI
     * atau DITOLAK.
     */
    public function buatPermohonan(array $data): PermohonanLayanan
    {
        return DB::transaction(function () use ($data) {
            $data['nomor_permohonan'] = $this->generatorNomor->generate(
                PermohonanLayanan::class,
                'nomor_permohonan',
                'PMH'
            );
            $data['status'] = StatusPermohonanEnum::MENUNGGU_PENGECEKAN_TEKNIS;

            $data['tipe_paket'] = $data['tipe_paket'] ?? 'reguler';

            // Ambil data layanan sebelumnya jika latitude/longitude kosong (misal: ganti paket)
            if (empty($data['latitude']) || empty($data['longitude']) || empty($data['alamat_pemasangan'])) {
                if (! empty($data['layanan_internet_id'])) {
                    $layanan = LayananInternet::find($data['layanan_internet_id']);

                    $data['alamat_pemasangan'] = $data['alamat_pemasangan'] ?? $layanan->alamat_pemasangan ?? 'Alamat dari layanan aktif';
                    $data['detail_alamat'] = $data['detail_alamat'] ?? $layanan->detail_alamat ?? null;
                    $data['latitude'] = $data['latitude'] ?? $layanan->latitude ?? '0.000000';
                    $data['longitude'] = $data['longitude'] ?? $layanan->longitude ?? '0.000000';
                } else {
                    $data['alamat_pemasangan'] = $data['alamat_pemasangan'] ?? 'Alamat default';
                    $data['latitude'] = '0.000000';
                    $data['longitude'] = '0.000000';
                }
            }

            $permohonan = $this->permohonanLayananRepository->create($data);

            $this->catatRiwayat(
                $permohonan,
                null,
                StatusPermohonanEnum::MENUNGGU_PENGECEKAN_TEKNIS,
                null,
                'Permohonan diajukan, menunggu pengecekan kelayakan jaringan.'
            );

            return $permohonan;
        });
    }

    /**
     * Validasi transisi lewat StatusPermohonanEnum::transisiValid(), lalu eksekusi
     * perubahan status + catat riwayat dalam satu transaksi.
     *
     * @throws TransisiStatusTidakValidException
     */
    public function ubahStatus(
        PermohonanLayanan $permohonan,
        StatusPermohonanEnum $statusBaru,
        ?Admin $diubahOleh = null,
        ?string $catatan = null,
    ): PermohonanLayanan {
        $statusSekarang = $permohonan->status;

        if (! in_array($statusBaru, $statusSekarang->transisiValid(), true)) {
            throw new TransisiStatusTidakValidException(
                "Tidak bisa mengubah status dari {$statusSekarang->value} ke {$statusBaru->value}."
            );
        }

        return DB::transaction(function () use ($permohonan, $statusBaru, $statusSekarang, $diubahOleh, $catatan) {
            $permohonan = $this->permohonanLayananRepository->update($permohonan, [
                'status' => $statusBaru,
            ]);

            $this->catatRiwayat($permohonan, $statusSekarang, $statusBaru, $diubahOleh?->id, $catatan);

            return $permohonan;
        });
    }

    private function catatRiwayat(
        PermohonanLayanan $permohonan,
        ?StatusPermohonanEnum $sebelum,
        StatusPermohonanEnum $sesudah,
        ?int $diubahOleh,
        ?string $catatan,
    ): void {
        RiwayatStatusPermohonan::create([
            'permohonan_layanan_id' => $permohonan->id,
            'status_sebelumnya' => $sebelum?->value,
            'status_sesudahnya' => $sesudah->value,
            'diubah_oleh' => $diubahOleh,
            'catatan' => $catatan,
        ]);
    }
}
