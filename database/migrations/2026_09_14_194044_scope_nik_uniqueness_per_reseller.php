<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropUnique('pelanggan_nik_unique');
            $table->dropUnique('pelanggan_nomor_hp_unique');
        });

        // Setiap reseller punya pool pelanggan sendiri: NIK & HP unik di
        // dalam pool reseller, tapi boleh sama dengan reseller lain / sistem utama.
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_reseller_unique ON pelanggan (nik, reseller_id)');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nomor_hp_reseller_unique ON pelanggan (nomor_hp, reseller_id)');

        // Pool sistem utama: NIK & HP tetap unik di antara pelanggan sistem utama.
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_sistem_utama_unique ON pelanggan (nik) WHERE reseller_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nomor_hp_sistem_utama_unique ON pelanggan (nomor_hp) WHERE reseller_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            DB::statement('DROP INDEX IF EXISTS pelanggan_nik_sistem_utama_unique');
            DB::statement('DROP INDEX IF EXISTS pelanggan_nomor_hp_sistem_utama_unique');
            DB::statement('DROP INDEX IF EXISTS pelanggan_nik_reseller_unique');
            DB::statement('DROP INDEX IF EXISTS pelanggan_nomor_hp_reseller_unique');
            $table->unique('nik');
            $table->unique('nomor_hp');
        });
    }
};