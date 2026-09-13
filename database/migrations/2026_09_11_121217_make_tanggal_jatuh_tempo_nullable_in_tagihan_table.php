tyo@tyo:~/Projek/sicakra/sicakra-api$ sed -n '1,220p' database/migrations/2026_09_08_124509_make_permohonan_layanan_id_nullable_on_layanan_internet_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('tagihan', function (Blueprint $table) {
                $table->date('tanggal_jatuh_tempo')->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE tagihan ALTER COLUMN tanggal_jatuh_tempo DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('tagihan', function (Blueprint $table) {
                $table->date('tanggal_jatuh_tempo')->nullable(false)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE tagihan ALTER COLUMN tanggal_jatuh_tempo SET NOT NULL');
    }
};