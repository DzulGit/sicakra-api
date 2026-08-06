<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Siklus penagihan Anniversary / Snap to End of Month:
     * - bebas_tagihan_bulan: promo gratis X bulan (default 0).
     * - tanggal_mulai_penagihan: tanggal PASTI kapan tagihan berbayar pertama di-generate.
     *   Dihitung = tanggal_aktif + (1 + bebas_tagihan_bulan) bulan, lalu di-snap ke
     *   tanggal_tagihan dasar pelanggan (31 -> hari terakhir bulan yg pendek).
     */
    public function up(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->unsignedTinyInteger('bebas_tagihan_bulan')->default(0)->after('harga_custom');
            $table->date('tanggal_mulai_penagihan')->nullable()->after('tanggal_aktif');
        });

        // Backfill layanan aktif yang sudah ada: tanggal_mulai_penagihan = siklus
        // berikutnya (>= hari ini) berbasis tanggal_aktif + tanggal_tagihan pelanggan.
        // Promo historis tidak diketahui -> bebas 0. Perilaku bulanan lama (hari
        // tanggal_tagihan) tetap terjaga karena basis harinya sama.
        $rows = DB::table('layanan_internet as li')
            ->join('pelanggan as p', 'p.id', '=', 'li.pelanggan_id')
            ->select('li.id', 'li.tanggal_aktif', 'p.tanggal_tagihan')
            ->where('li.status', 'aktif')
            ->whereNull('li.tanggal_mulai_penagihan')
            ->get();

        $hariIni = Carbon::today();

        foreach ($rows as $row) {
            if (! $row->tanggal_aktif) {
                continue;
            }

            $hariDasar = (int) ($row->tanggal_tagihan ?: Carbon::parse($row->tanggal_aktif)->day);
            $pertama = Carbon::parse($row->tanggal_aktif)->addMonthsNoOverflow(1);

            $tanggal = $this->snap($pertama, $hariDasar);
            $guard = 0;
            while ($tanggal->lt($hariIni) && $guard < 1200) {
                $tanggal = $this->snap($tanggal->copy()->addMonthNoOverflow(1), $hariDasar);
                $guard++;
            }

            DB::table('layanan_internet')->where('id', $row->id)
                ->update(['tanggal_mulai_penagihan' => $tanggal->toDateString()]);
        }
    }

    public function down(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->dropColumn(['bebas_tagihan_bulan', 'tanggal_mulai_penagihan']);
        });
    }

    private function snap(Carbon $tanggal, int $hariDasar): Carbon
    {
        $hari = min(max(1, $hariDasar), 31);

        return $tanggal->copy()->setDay(min($hari, $tanggal->daysInMonth));
    }
};
