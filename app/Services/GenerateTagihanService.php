<?php

namespace App\Services;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\TipePaketEnum;
use App\Events\TagihanDibuat;
use App\Models\LayananInternet;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateTagihanService
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
        private readonly GeneratorNomorService $generatorNomor,
    ) {}

    /**
     * Generate 1 tagihan untuk 1 layanan pada periode tertentu.
     * Idempotent: kalau tagihan periode itu sudah ada, tidak dibuat dobel
     * (dijaga juga oleh unique constraint DB sebagai lapisan pengaman terakhir).
     */
    public function generateUntukLayanan(LayananInternet $layanan, int $periodeBulan, int $periodeTahun, int $jumlahBulan = 1): ?Tagihan
    {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        $sudahAda = Tagihan::where('layanan_internet_id', $layanan->id)
            ->where('periode_bulan', $periodeBulan)
            ->where('periode_tahun', $periodeTahun)
            ->exists();

        if ($sudahAda) {
            return null;
        }

        return DB::transaction(function () use ($layanan, $periodeBulan, $periodeTahun, $jumlahBulan) {
            [$namaPaket, $kecepatan, $harga] = $this->snapshotPaket($layanan);

            $nomorTagihan = $this->generatorNomor->generate(Tagihan::class, 'nomor_tagihan', 'INV');

            $tagihan = $this->tagihanRepository->create([
                'nomor_tagihan' => $nomorTagihan,
                'layanan_internet_id' => $layanan->id,
                'periode_bulan' => $periodeBulan,
                'periode_tahun' => $periodeTahun,
                'nama_paket_snapshot' => $namaPaket,
                'kecepatan_snapshot_mbps' => $kecepatan,
                'harga_snapshot' => $harga,
                'total_tagihan' => $harga * $jumlahBulan,
                'jumlah_bulan' => $jumlahBulan,
                'tanggal_jatuh_tempo' => $this->hitungTanggalJatuhTempo($layanan, $periodeBulan, $periodeTahun),
                'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            ]);

            TagihanDibuat::dispatch($tagihan);

            return $tagihan;
        });
    }

    private function snapshotPaket(LayananInternet $layanan): array
    {
        if ($layanan->tipe_paket === TipePaketEnum::REGULER) {
            $paket = $layanan->paketInternet;

            return [$paket->nama_paket, $paket->kecepatan_mbps, $paket->harga];
        }

        return [$layanan->nama_paket_custom, $layanan->kecepatan_custom_mbps, $layanan->harga_custom];
    }

    /**
     * Tanggal jatuh tempo mengikuti tanggal_aktif layanan, di-clamp kalau bulan
     * tujuan lebih pendek (mis. aktif tanggal 31, tapi Februari cuma 28/29 hari).
     */
    private function hitungTanggalJatuhTempo(LayananInternet $layanan, int $bulan, int $tahun): string
    {
        $hariAktif = Carbon::parse($layanan->tanggal_aktif)->day;
        $jumlahHariDiBulanTujuan = Carbon::createFromDate($tahun, $bulan, 1)->daysInMonth;

        $hari = min($hariAktif, $jumlahHariDiBulanTujuan);

        return Carbon::createFromDate($tahun, $bulan, $hari)->toDateString();
    }
}