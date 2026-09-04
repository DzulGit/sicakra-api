<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deteksi & isi provinsi/kota dari string alamat yang sudah ada.
        foreach (['permohonan_layanan', 'layanan_internet'] as $tabel) {
            if (Schema::hasColumn($tabel, 'provinsi') || Schema::hasColumn($tabel, 'kota')) {
                continue;
            }
            Schema::table($tabel, function (Blueprint $table) {
                $table->string('provinsi')->nullable()->after('alamat_pemasangan');
                $table->string('kota')->nullable()->after('provinsi');
            });

            // Backfill parsing alamat Nominatim: [.., kota, provinsi, kodepos, 'Indonesia']
            $rows = DB::table($tabel)
                ->whereNotNull('alamat_pemasangan')
                ->select('id', 'alamat_pemasangan')
                ->get();

            foreach ($rows as $row) {
                [$provinsi, $kota] = $this->parseAlamat($row->alamat_pemasangan);
                if ($provinsi || $kota) {
                    DB::table($tabel)->where('id', $row->id)->update([
                        'provinsi' => $provinsi,
                        'kota' => $kota,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['permohonan_layanan', 'layanan_internet'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropColumn(['provinsi', 'kota']);
            });
        }
    }

    /**
     * Parse string alamat menjadi [provinsi, kota].
     * Format Nominatim (id): [jalan, ..., desa, kecamatan, kota, provinsi, kodepos, 'Indonesia'].
     */
    private function parseAlamat(string $alamat): array
    {
        $bagian = array_map('trim', array_filter(array_map('trim', explode(',', $alamat)), fn ($b) => $b !== ''));

        if (count($bagian) < 3) {
            return [null, null];
        }

        $provinsi = null;
        $kota = null;

        // Cari dari belakang: 'Indonesia' sebagai penanda akhir
        $end = count($bagian) - 1;
        if (strcasecmp(end($bagian), 'Indonesia') === 0) {
            $end--;
        }

        // Elemen sebelum kodepos (numeric) adalah provinsi
        while ($end >= 0 && is_numeric($bagian[$end])) {
            $end--;
        }

        if ($end < 0) {
            return [null, null];
        }

        $provinsi = $bagian[$end];

        // Kota = elemen sebelum provinsi (abaikan yang numerik/berikutnya)
        $kotaIdx = $end - 1;
        while ($kotaIdx >= 0 && is_numeric($bagian[$kotaIdx])) {
            $kotaIdx--;
        }
        if ($kotaIdx >= 0) {
            $kota = $bagian[$kotaIdx];
        }

        return [$provinsi, $kota];
    }
};
