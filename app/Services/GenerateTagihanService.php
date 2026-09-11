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
     * Idempotent: kalau tagihan periode itu sudah ada, tidak dibuat dobel.
     * Dipakai untuk generate manual admin (emergency) & tagihan pertama.
     */
    public function generateUntukLayanan(
        LayananInternet $layanan,
        int $periodeBulan,
        int $periodeTahun,
        int $jumlahBulan = 1,
    ): ?Tagihan {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        if ($this->periodeSudahTercover($layanan, $periodeBulan, $periodeTahun)) {
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
                'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            ]);

            TagihanDibuat::dispatch($tagihan);

            return $tagihan;
        });
    }

    /**
     * Generate tagihan DRAFT (belum_diterbitkan) untuk 1 layanan.
     * Dipakai cron tanggal 1 — tagihan tidak langsung aktif, menunggu admin/reseller terbitkan.
     * Tidak dispatch event TagihanDibuat (event baru jalan saat terbitkan).
     */
    public function generateDraftUntukLayanan(
        LayananInternet $layanan,
        int $periodeBulan,
        int $periodeTahun,
        int $jumlahBulan = 1,
    ): ?Tagihan {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        if ($this->periodeSudahTercover($layanan, $periodeBulan, $periodeTahun)) {
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
                'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
            ]);

            // ponytail: tidak dispatch TagihanDibuat — event baru saat admin/reseller terbitkan

            return $tagihan;
        });
    }

    public function hitungTagihanPertama(
        LayananInternet $layanan,
        string $mode,
    ): array {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            throw new \InvalidArgumentException(
                'Layanan belum aktif.'
            );
        }

        if (Tagihan::where('layanan_internet_id', $layanan->id)->exists()) {
            throw new \InvalidArgumentException(
                'Tagihan pertama untuk layanan ini sudah pernah dibuat.'
            );
        }

        $mode = strtolower($mode);

        if (! in_array($mode, ['prorata', 'full'], true)) {
            throw new \InvalidArgumentException(
                'Mode tagihan pertama harus prorata atau full.'
            );
        }

        if (! $layanan->tanggal_aktif) {
            throw new \InvalidArgumentException(
                'Tanggal aktif layanan belum tersedia.'
            );
        }

        [$namaPaket, $kecepatan, $harga] = $this->snapshotPaket($layanan);

        $tanggalAktif = Carbon::parse($layanan->tanggal_aktif);
        $hargaBulanan = (float) $harga;

        $jumlahHariDalamBulan = $tanggalAktif->daysInMonth;

        $jumlahHari = $tanggalAktif->diffInDays(
            $tanggalAktif->copy()->endOfMonth()
        ) + 1;

        // $nominalProrata = round(
        //     ($hargaBulanan / $jumlahHariDalamBulan) * $jumlahHari,
        //     2
        // );
        $nominalProrata = round(
            ($jumlahHari / $jumlahHariDalamBulan) * $hargaBulanan,
            2
        );

        $nominalFull = round($hargaBulanan, 2);

        $nominalTerhitung = $mode === 'prorata'
            ? $nominalProrata
            : $nominalFull;

        return [
            'mode' => $mode,
            'tanggal_aktif' => $tanggalAktif->toDateString(),
            'periode_bulan' => $tanggalAktif->month,
            'periode_tahun' => $tanggalAktif->year,
            'nama_paket' => $namaPaket,
            'kecepatan_mbps' => $kecepatan,
            'harga_bulanan' => $hargaBulanan,
            'jumlah_hari' => $mode === 'prorata'
                ? $jumlahHari
                : $jumlahHariDalamBulan,
            'jumlah_hari_dalam_bulan' => $jumlahHariDalamBulan,
            'nominal_prorata' => $nominalProrata,
            'nominal_full' => $nominalFull,
            'nominal_terhitung' => $nominalTerhitung,
        ];
    }

    public function generateTagihanPertama(
        LayananInternet $layanan,
        string $mode,
        ?float $nominalManual = null,
    ): ?Tagihan {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        $mode = strtolower($mode);

        if (! in_array($mode, ['prorata', 'full'], true)) {
            throw new \InvalidArgumentException(
                'Mode tagihan pertama harus prorata atau full.'
            );
        }

        $perhitungan = $this->hitungTagihanPertama($layanan, $mode);

        $namaPaket = $perhitungan['nama_paket'];
        $kecepatan = $perhitungan['kecepatan_mbps'];
        $hargaBulanan = $perhitungan['harga_bulanan'];

        $periodeBulan = $perhitungan['periode_bulan'];
        $periodeTahun = $perhitungan['periode_tahun'];

        $nominalTerhitung = $perhitungan['nominal_terhitung'];

        $totalTagihan = $nominalManual !== null
            ? $nominalManual
            : round($nominalTerhitung, 2);

        if ($totalTagihan < 0) {
            throw new \InvalidArgumentException(
                'Nominal tagihan tidak boleh kurang dari 0.'
            );
        }

        return DB::transaction(function () use (
            $layanan,
            $namaPaket,
            $kecepatan,
            $hargaBulanan,
            $totalTagihan,
            $periodeBulan,
            $periodeTahun,
        ) {
            $nomorTagihan = $this->generatorNomor->generate(
                Tagihan::class,
                'nomor_tagihan',
                'INV'
            );

            $tagihan = $this->tagihanRepository->create([
                'nomor_tagihan' => $nomorTagihan,
                'layanan_internet_id' => $layanan->id,
                'periode_bulan' => $periodeBulan,
                'periode_tahun' => $periodeTahun,
                'nama_paket_snapshot' => $namaPaket,
                'kecepatan_snapshot_mbps' => $kecepatan,
                'harga_snapshot' => $hargaBulanan,
                'total_tagihan' => $totalTagihan,
                'jumlah_bulan' => 1,
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
     * Dipakai generate manual: true bila periode (bulan,tahun) sudah ter-cover
     * ter-cover tagihan yang ada. Rentang tagihan = [periode..periode+jumlah_bulan-1].
     */
    public function periodeSudahTercover(LayananInternet $layanan, int $periodeBulan, int $periodeTahun): bool
    {
        return Tagihan::where('layanan_internet_id', $layanan->id)
            ->get()
            ->contains(function (Tagihan $t) use ($periodeBulan, $periodeTahun) {
                $mulai = Carbon::createFromDate($t->periode_tahun, $t->periode_bulan, 1)->startOfDay();
                $akhir = $mulai->copy()->addMonthsNoOverflow(max(1, (int) $t->jumlah_bulan))->subMonth()->endOfDay();
                $target = Carbon::createFromDate($periodeTahun, $periodeBulan, 1)->startOfDay();

                return $target->between($mulai, $akhir);
            });
    }
}
