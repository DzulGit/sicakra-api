<?php

namespace App\Services;

use App\Enums\StatusLaporanEnum;
use App\Exceptions\TransisiStatusTidakValidException;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\Pelanggan;
use App\Notifications\LaporanKendalaDitugaskanNotification;
use App\Notifications\LaporanKendalaDiterimaNotification;
use App\Notifications\LaporanKendalaStatusNotification;
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

            if (isset($data['foto']) && is_array($data['foto'])) {
                $paths = [];
                foreach ($data['foto'] as $file) {
                    $paths[] = $file->store('laporan-kendala', 's3');
                }
                
                // Simpan sebagai JSON agar mendukung banyak gambar
                $data['foto'] = json_encode($paths); 
            }

            return $this->laporanKendalaRepository->create($data);
        });
    }
    public function terima(LaporanKendala $laporan): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DIPROSES);

        $laporan->layananInternet?->pelanggan?->notify(
            new LaporanKendalaDiterimaNotification($laporan)
        );

        return $laporan;
    }

    public function teruskanKeTeknisi(LaporanKendala $laporan, Admin $teknisiTujuan): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DITUGASKAN);

        $laporan = $this->laporanKendalaRepository->update($laporan, [
            'ditugaskan_ke' => $teknisiTujuan->id,
        ]);

        if ($teknisiTujuan->email) {
            $teknisiTujuan->notify(new LaporanKendalaDitugaskanNotification($laporan));
        }

        return $laporan;
    }

    public function selesaikan(LaporanKendala $laporan, string $hasilPenanganan): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::SELESAI);

        $laporan = $this->laporanKendalaRepository->update($laporan, [
            'hasil_penanganan' => $hasilPenanganan,
        ]);

        $laporan->layananInternet?->pelanggan?->notify(
            new LaporanKendalaStatusNotification($laporan, StatusLaporanEnum::SELESAI, $hasilPenanganan)
        );

        return $laporan;
    }

    public function tutup(LaporanKendala $laporan, Admin $operasional): LaporanKendala
    {
        $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DITUTUP);

        $laporan = $this->laporanKendalaRepository->update($laporan, [
            'ditutup_oleh' => $operasional->id,
        ]);

        $laporan->layananInternet?->pelanggan?->notify(
            new LaporanKendalaStatusNotification($laporan, StatusLaporanEnum::DITUTUP)
        );

        return $laporan;
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

    public function tindakLanjut(LaporanKendala $laporan, array $data, Admin $operasional): LaporanKendala
    {
        return DB::transaction(function () use ($laporan, $data, $operasional) {
            if ($data['keputusan'] === 'SELESAI_REMOTE') {
                $laporan = $this->laporanKendalaRepository->update($laporan, [
                    'hasil_penanganan' => $data['hasil_penanganan'],
                    'ditutup_oleh' => $operasional->id,
                ]);

                $laporan = $this->ubahStatus($laporan, StatusLaporanEnum::DITUTUP);

                $laporan->layananInternet?->pelanggan?->notify(
                    new LaporanKendalaStatusNotification($laporan, StatusLaporanEnum::DITUTUP, $data['hasil_penanganan'])
                );

                return $laporan;
            }

            // Ambil teknisi pertama dari array yang dipilih
            $teknisi = Admin::findOrFail($data['teknisi_ids'][0]);
            
            // Simpan tanggal_kerja juga jika tabel database LaporanKendala sudah memiliki kolom tanggal_kerja
            return $this->teruskanKeTeknisi($laporan, $teknisi);
        });
    }
}