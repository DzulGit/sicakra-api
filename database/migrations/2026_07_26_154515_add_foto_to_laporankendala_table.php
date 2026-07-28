<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laporan_kendala', function (Blueprint $table) {
            // Opsional — pelanggan boleh melaporkan kendala tanpa foto.
            // Simpan path storage-nya saja (bukan file mentah), mengikuti
            // pola foto_ktp/foto_selfie_ktp di tabel pelanggan.
            $table->string('foto')->nullable()->after('deskripsi');
        });
    }

    public function down(): void
    {
        Schema::table('laporan_kendala', function (Blueprint $table) {
            $table->dropColumn('foto');
        });
    }
};