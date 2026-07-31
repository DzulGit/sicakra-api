<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            // Hapus kolom rt, rw, kode_pos jika ada
            $columnsToRemove = ['rt', 'rw', 'kode_pos', 'provinsi', 'kabupaten', 'kecamatan', 'kelurahan'];
            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('layanan_internet', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Pastikan detail_alamat ada
            if (!Schema::hasColumn('layanan_internet', 'detail_alamat')) {
                $table->text('detail_alamat')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->string('rt')->nullable();
            $table->string('rw')->nullable();
            $table->string('kode_pos')->nullable();
            $table->dropColumn('detail_alamat');
        });
    }
};