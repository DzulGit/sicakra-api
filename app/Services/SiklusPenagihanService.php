<?php

namespace App\Services;

use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Models\Tagihan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Siklus penagihan bulanan "Anniversary / Snap to End of Month".
 *
 * - Hari dasar = tanggal_tagihan pelanggan (diambil dari tanggal aktivasi).
 * - Tanggal mulai penagihan = tanggal_aktif + (1 + bebas_tagihan_bulan) bulan.
 * - Snap: kalau hari dasar (mis. 31) melebihi jumlah hari bulan target, jatuh ke
 *   hari terakhir bulan tersebut; bulan 31-hari berikutnya otomatis kembali ke 31.
 */
class SiklusPenagihanService
{
    public function __construct(
        private readonly GenerateTagihanService $generateTagihanService,
    ) {}

    /** Tanggal penagihan berbayar pertama = aktivasi + (1 + bebas_tagihan_bulan), snap. */
    public function tanggalMulaiPenagihan(LayananInternet $layanan): ?Carbon
    {
        if (! $layanan->tanggal_aktif) {
            return null;
        }

        $mulai = Carbon::parse($layanan->tanggal_aktif)
            ->addMonthsNoOverflow(1 + (int) $layanan->bebas_tagihan_bulan);

        return $this->snapKeBulan($mulai, $this->hariDasar($layanan));
    }

    /** Siklus berikutnya = +1 bulan dari tanggal sekarang, tetap snap ke hari dasar. */
    public function siklusBerikutnya(LayananInternet $layanan, Carbon $sekarang): Carbon
    {
        return $this->snapKeBulan($sekarang->copy()->addMonthNoOverflow(1), $this->hariDasar($layanan));
    }

    /**
     * Snap to End of Month: day 31 di bulan pendek -> hari terakhir; kembalikan 31
     * di bulan yang punya 31 hari.
     */
    public function snapKeBulan(Carbon $tanggal, int $hariDasar): Carbon
    {
        $hari = min(max(1, $hariDasar), 31);

        return $tanggal->copy()->setDay(min($hari, $tanggal->daysInMonth));
    }

    /**
     * Set jadwal awal saat layanan baru diaktifkan. Kalau hasilnya sudah lewat
     * (mis. admin mengubah bebas_tagihan_bulan di kemudian hari), digulir ke siklus
     * berikutnya supaya tidak berhenti ditagih.
     */
    public function aturJadwalAwal(LayananInternet $layanan): void
    {
        $tanggal = $this->tanggalMulaiPenagihan($layanan);

        if (! $tanggal) {
            return;
        }

        $hariIni = Carbon::today();
        while ($tanggal->lt($hariIni)) {
            $tanggal = $this->siklusBerikutnya($layanan, $tanggal);
        }

        $layanan->update(['tanggal_mulai_penagihan' => $tanggal->toDateString()]);
    }

    /**
     * Cron harian: tagih layanan yang tanggal_mulai_penagihan-nya jatuh hari ini,
     * lalu majukan jadwal 1 bulan berulang. Mengembalikan jumlah layanan yang diproses.
     */
    public function prosesHariIni(): int
    {
        $hariIni = Carbon::today();
        $diproses = 0;

        LayananInternet::where('status', StatusLayananEnum::AKTIF)
            ->whereDate('tanggal_mulai_penagihan', $hariIni)
            ->with('pelanggan')
            ->chunkById(100, function ($kumpulan) use (&$diproses, $hariIni) {
                foreach ($kumpulan as $layanan) {
                    try {
                        $this->prosesSatuLayanan($layanan, $hariIni);
                        $diproses++;
                    } catch (\Throwable $e) {
                        Log::error("Gagal generate tagihan terjadwal untuk layanan #{$layanan->id}: {$e->getMessage()}");
                    }
                }
            });

        return $diproses;
    }

    /**
     * Generate tagihan untuk periode tanggal_mulai_penagihan, lalu majukan jadwal.
     * Jadwal tetap dimajukan walau generate menghasilkan null (tagihan periode itu
     * sudah ada / idempotent) supaya tidak stuck di hari yang sama.
     */
    public function prosesSatuLayanan(LayananInternet $layanan, ?Carbon $hariIni = null): ?Tagihan
    {
        $jadwal = Carbon::parse($layanan->tanggal_mulai_penagihan ?? $hariIni ?? Carbon::today());

        $tagihan = $this->generateTagihanService->generateUntukLayanan(
            $layanan,
            $jadwal->month,
            $jadwal->year,
        );

        $layanan->update([
            'tanggal_mulai_penagihan' => $this->siklusBerikutnya($layanan, $jadwal)->toDateString(),
        ]);

        return $tagihan;
    }

    /**
     * Saat tagihan multi-bulan dibayar, majukan jadwal ke periode pertama yang
     * belum terbayar = periode tagihan + jumlah_bulan.
     *
     * JANGAN memajukan tanggal_mulai_penagihan saat ini dengan jumlah_bulan: cron
     * sudah memajukan jadwal +1 bulan begitu tagihan dibuat (jadwal sekarang = periode
     * tagihan + 1). Memajukan lagi akan melewati 1 periode dan pelanggan dapat 1 bulan
     * gratis. Berpijak ke periode tagihan juga kebal terhadap tagihan manual / telat.
     */
    public function majukanJadwalSetelahPembayaran(Tagihan $tagihan): void
    {
        $layanan = $tagihan->layananInternet;
        if (! $layanan) {
            return;
        }

        $periode = Carbon::createFromDate($tagihan->periode_tahun, $tagihan->periode_bulan, 1)
            ->addMonthsNoOverflow((int) $tagihan->jumlah_bulan);

        $tanggal = $this->snapKeBulan($periode, $this->hariDasar($layanan));

        // Tagihan lama yang baru dibayar: jadwal hasil hitungan bisa sudah lewat hari
        // ini. Gulir maju ke siklus berikutnya supaya cron tidak stuck di tanggal lalu.
        $hariIni = Carbon::today();
        while ($tanggal->lt($hariIni)) {
            $tanggal = $this->siklusBerikutnya($layanan, $tanggal);
        }

        $layanan->update(['tanggal_mulai_penagihan' => $tanggal->toDateString()]);
    }

    private function hariDasar(LayananInternet $layanan): int
    {
        $hari = $layanan->pelanggan?->tanggal_tagihan;

        return $hari >= 1 && $hari <= 31 ? $hari : 20;
    }
}
