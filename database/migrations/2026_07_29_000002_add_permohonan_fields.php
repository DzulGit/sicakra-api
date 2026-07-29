<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->unsignedBigInteger('paket_internet_id_baru')->nullable()->after('paket_internet_id');
            $table->text('alasan')->nullable()->after('catatan_custom');
        });

        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->text('detail_alamat')->nullable()->after('alamat_pemasangan');
        });
    }

    public function down(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->dropColumn(['paket_internet_id_baru', 'alasan']);
        });

        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->dropColumn('detail_alamat');
        });
    }
};
