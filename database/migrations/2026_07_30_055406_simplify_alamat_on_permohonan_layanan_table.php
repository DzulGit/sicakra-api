<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->dropColumn(['rt', 'rw', 'kode_pos']);
            
            if (!Schema::hasColumn('permohonan_layanan', 'detail_alamat')) {
                $table->text('detail_alamat')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->string('rt')->nullable();
            $table->string('rw')->nullable();
            $table->string('kode_pos')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kabupaten')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();
            $table->dropColumn('detail_alamat');
        });
    }
};