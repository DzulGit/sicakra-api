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
     *
     * `$tanggalJatuhTempo` opsional: dipakai flow manual admin (tanggal tagihan
     * hari ini + jumlah hari jatuh tempo). Null → jatuh tempo = hari penagihan
     * bulan periode (perilaku lama).
     */
    public function generateUntukLayanan(
        LayananInternet $layanan,
        int $periodeBulan,
        int $periodeTahun,
        int $jumlahBulan = 1,
        ?Carbon $tanggalJatuhTempo = null,
    ): ?Tagihan {
        if ($layanan->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        $isTagihanPertama = ! Tagihan::where('layanan_internet_id', $layanan->id)->exists();

        if (! $isTagihanPertama) {
            // Penagihan dijadwalkan via tanggal_tagihan pelanggan (bukan hardcoded 20).
            // Pakai tanggal jatuh tempo yang sudah di-snap, biar Carbon::create tidak
            // roll-over kalau hari dasar 31 jatuh di bulan pendek (mis. Februari).
            $targetEksekusi = ($tanggalJatuhTempo ?? Carbon::parse(
                $this->hitungTanggalJatuhTempo($layanan, $periodeBulan, $periodeTahun)
            ))->startOfDay();
            $batasAktif = Carbon::parse($layanan->tanggal_aktif)->startOfDay();

            // Blokir jika hari penagihan di bulan target masih sebelum masa aktif habis
            if ($targetEksekusi->lessThan($batasAktif)) {
                return null;
            }
        }

        // Guard anti-tagihan-ganda: blokir kalau periode yang diminta sudah ter-cover
        // tagihan yang ada — baik periode yang sama PERSIS, maupun periode yang jatuh di
        // dalam rentang tagihan multi-bulan (mis. tagihan Januari jumlah_bulan=3 sudah
        // meng-cover Feb & Mar, jadi jangan generate tagihan Feb nya lagi).
        if ($this->periodeSudahTercover($layanan, $periodeBulan, $periodeTahun)) {
            return null;
        }

        return DB::transaction(function () use ($layanan, $periodeBulan, $periodeTahun, $jumlahBulan, $tanggalJatuhTempo) {
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
                'tanggal_jatuh_tempo' => $tanggalJatuhTempo
                    ? $tanggalJatuhTempo->toDateString()
                    : $this->hitungTanggalJatuhTempo($layanan, $periodeBulan, $periodeTahun),
                'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            ]);

            TagihanDibuat::dispatch($tagihan);

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

        $nominalProrata = round(
            ($hargaBulanan / $jumlahHariDalamBulan) * $jumlahHari,
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
        ?Carbon $tanggalJatuhTempo = null,
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
            $tanggalJatuhTempo,
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
                'tanggal_jatuh_tempo' => $tanggalJatuhTempo
                    ? $tanggalJatuhTempo->toDateString()
                    : Carbon::today()->toDateString(),
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

    private function hariPenagihan(LayananInternet $layanan): int
    {
        $hari = $layanan->pelanggan?->tanggal_tagihan;

        return $hari >= 1 && $hari <= 31 ? $hari : 20;
    }

    private function hitungTanggalJatuhTempo(LayananInternet $layanan, int $bulan, int $tahun): string
    {
        $hariPenagihan = $this->hariPenagihan($layanan);
        $jumlahHariDiBulanTujuan = Carbon::createFromDate($tahun, $bulan, 1)->daysInMonth;

        $hari = min($hariPenagihan, $jumlahHariDiBulanTujuan);

        return Carbon::createFromDate($tahun, $bulan, $hari)->toDateString();
    }
}
