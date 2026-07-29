<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->text('detail_alamat')->nullable()->after('alamat_pemasangan');
            $table->string('rt', 3)->nullable()->change();
            $table->string('rw', 3)->nullable()->change();
            $table->string('kode_pos', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('permohonan_layanan', function (Blueprint $table) {
            $table->dropColumn('detail_alamat');
            $table->string('rt', 3)->nullable(false)->change();
            $table->string('rw', 3)->nullable(false)->change();
            $table->string('kode_pos', 5)->nullable(false)->change();
        });
    }
};
