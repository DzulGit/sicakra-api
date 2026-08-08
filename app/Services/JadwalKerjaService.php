<?php

namespace App\Services;

use App\Enums\HasilKerjaEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\PermohonanLayanan;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use Illuminate\Support\Facades\DB;

class JadwalKerjaService
{
    public function __construct(
        private readonly JadwalKerjaRepositoryInterface $jadwalKerjaRepository,
        private readonly PermohonanLayananService $permohonanLayananService,
        private readonly KonversiPermohonanService $konversiPermohonanService,
    ) {}

    /**
     * Dipakai untuk penjadwalan AWAL (dari DITERIMA) MAUPUN reschedule
     * setelah DITUNDA karena ada kendala di kunjungan sebelumnya.
     */
    public function jadwalkan(
        PermohonanLayanan $permohonan,
        array $teknisiIds,
        string $tanggalKerja,
        Admin $diprosesOleh,
        ?int $timTeknisiId = null,
    ): JadwalKerja {
        return DB::transaction(function () use ($permohonan, $teknisiIds, $tanggalKerja, $diprosesOleh, $timTeknisiId) {
            $jadwal = $this->jadwalKerjaRepository->create([
                'permohonan_layanan_id' => $permohonan->id,
                'tim_teknisi_id' => $timTeknisiId,
                'tanggal_kerja' => $tanggalKerja,
            ]);
            $jadwal->teknisi()->sync($teknisiIds);

            $catatan = $permohonan->status === StatusPermohonanEnum::DITUNDA
                ? 'Jadwal kerja ulang dibuat setelah kendala.'
                : 'Jadwal kerja dibuat.';

            $this->permohonanLayananService->ubahStatus(
                $permohonan, StatusPermohonanEnum::DIJADWALKAN, $diprosesOleh, $catatan
            );

            return $jadwal->load(['teknisi', 'timTeknisi']);
        });
    }

    /**
     * Teknisi (anggota tim manapun) mengisi hasil kunjungan.
     *
     * - selesai -> trigger KonversiPermohonanService (sama seperti sebelumnya:
     *   bikin/update layanan_internet + generate nomor_pelanggan kalau layanan
     *   pertama), lalu kembalikan RINGKASAN AKTIVASI supaya teknisi bisa
     *   langsung edukasi pelanggan di lokasi (username, paket, status).
     * - kendala -> status balik ke DITUNDA, menunggu Operasional reschedule.
     *
     * @return array{jadwal: JadwalKerja, ringkasan_aktivasi: array|null}
     */
    public function isiHasil(
        JadwalKerja $jadwal,
        HasilKerjaEnum $hasil,
        ?string $catatanKendala,
        Admin $teknisi,
        array $fotoDokumentasi = [],
        ?float $latitudeHasil = null,
        ?float $longitudeHasil = null,
    ): array {
        return DB::transaction(function () use ($jadwal, $hasil, $catatanKendala, $teknisi, $fotoDokumentasi, $latitudeHasil, $longitudeHasil) {
            $jadwal = $this->jadwalKerjaRepository->update($jadwal, [
                'hasil' => $hasil,
                'catatan_kendala' => $catatanKendala,
                'foto_dokumentasi' => $fotoDokumentasi ?: null,
                'latitude_hasil' => $latitudeHasil,
                'longitude_hasil' => $longitudeHasil,
                'diisi_oleh' => $teknisi->id,
            ]);

            $permohonan = $jadwal->permohonanLayanan;

            if ($hasil === HasilKerjaEnum::SELESAI) {
                // Titik koordinat aktual dari lokasi — dipakai sebagai dasar
                // layanan & riwayat (mis. relokasi) agar akurat bukan copy alamat.
                if ($latitudeHasil !== null && $longitudeHasil !== null) {
                    $permohonan->update([
                        'latitude' => $latitudeHasil,
                        'longitude' => $longitudeHasil,
                    ]);
                }

                $layanan = $this->konversiPermohonanService->konversi($permohonan, $teknisi);
                $layanan->load(['pelanggan', 'paketInternet']);

                $namaPaket = $layanan->tipe_paket === TipePaketEnum::REGULER
                    ? $layanan->paketInternet?->nama_paket
                    : $layanan->nama_paket_custom;
                $kecepatan = $layanan->tipe_paket === TipePaketEnum::REGULER
                    ? $layanan->paketInternet?->kecepatan_mbps
                    : $layanan->kecepatan_custom_mbps;

                return [
                    'jadwal' => $jadwal,
                    'ringkasan_aktivasi' => [
                        'nomor_pelanggan' => $layanan->pelanggan->nomor_pelanggan,
                        'nama_pelanggan' => $layanan->pelanggan->nama_lengkap,
                        'nomor_layanan' => $layanan->nomor_layanan,
                        'nama_paket' => $namaPaket,
                        'kecepatan_mbps' => $kecepatan,
                        'status' => $layanan->status->value,
                    ],
                ];
            }

            $this->permohonanLayananService->ubahStatus(
                $permohonan, StatusPermohonanEnum::DITUNDA, $teknisi, $catatanKendala ?? 'Ada kendala di lokasi.'
            );
            $permohonan->update(['alasan_ditunda' => $catatanKendala]);

            return ['jadwal' => $jadwal, 'ringkasan_aktivasi' => null];
        });
    }
}