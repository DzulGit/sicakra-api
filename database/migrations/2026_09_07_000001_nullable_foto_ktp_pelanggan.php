<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reseller (mitra eksternal) mendaftarkan pelanggan tanpa foto KTP —
     * kolom foto_ktp dijadikan nullable mengikuti pola foto_selfie_ktp.
     */
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('foto_ktp')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('foto_ktp')->nullable(false)->change();
        });
    }
};
