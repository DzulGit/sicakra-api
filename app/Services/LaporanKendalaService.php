<?php

namespace App\Services;

use App\Enums\StatusLaporanEnum;
use App\Exceptions\TransisiStatusTidakValidException;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\Pelanggan;
use App\Repositories\Contracts\LaporanKendalaRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class LaporanKendalaService
{
    public function __construct(
        private readonly LaporanKendalaRepositoryInterface $laporanKendalaRepository,
        private readonly GeneratorNomorService $generatorNomor,
    ) {}

    public function buat(array $data, Pelanggan|Admin $pembuat): LaporanKendala
    {
        return DB::transaction(function () use ($data) {
            $data['nomor_laporan'] = $this->generatorNomor->generate(LaporanKendala::class, 'nomor_laporan', 'LPR');
            $data['status'] = StatusLaporanEnum::MENUNGGU;

            // Foto opsional — hanya diproses kalau pelanggan benar-benar
            // mengunggah file. Disimpan sebagai path string di kolom `foto`,
            // mengikuti pola foto_ktp/foto_selfie_ktp di PendaftaranService.
            if (isset($data['foto']) && $data['foto'] instanceof UploadedFile) {
                $data['foto'] = $data['foto']->store('laporan-kendala', 's3');
            } else {
                unset($data['foto']);
            }

            return $this->laporanKendalaRepository->create($data);
        });
    }

    public function terima(LaporanKendala $laporan): LaporanKendala
    {
        return $this->ubahStatus($laporan, StatusLaporanEnum::DIPROSES);
    }

    public function teruskanKeTeknisi(LaporanKendala $laporan, Admin $teknisiTujuan): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DITUGASKAN);

        return $this->laporanKendalaRepository->update($laporan, [
            'ditugaskan_ke' => $teknisiTujuan->id,
        ]);
    }

    public function selesaikan(LaporanKendala $laporan, string $hasilPenanganan): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::SELESAI);

        return $this->laporanKendalaRepository->update($laporan, [
            'hasil_penanganan' => $hasilPenanganan,
        ]);
    }

    public function tutup(LaporanKendala $laporan, Admin $operasional): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DITUTUP);

        return $this->laporanKendalaRepository->update($laporan, [
            'ditutup_oleh' => $operasional->id,
        ]);
    }

    private function ubahStatus(LaporanKendala $laporan, StatusLaporanEnum $statusBaru): LaporanKendala
    {
        if (! in_array($statusBaru, $laporan->status->transisiValid(), true)) {
            throw new TransisiStatusTidakValidException(
                "Tidak bisa mengubah status laporan dari {$laporan->status->value} ke {$statusBaru->value}."
            );
        }

        return $this->laporanKendalaRepository->update($laporan, ['status' => $statusBaru]);
    }
}