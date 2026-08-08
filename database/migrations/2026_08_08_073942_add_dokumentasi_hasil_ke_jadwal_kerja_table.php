<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_kerja', function (Blueprint $table) {
            $table->json('foto_dokumentasi')->nullable();
            $table->decimal('latitude_hasil', 10, 7)->nullable();
            $table->decimal('longitude_hasil', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_kerja', function (Blueprint $table) {
            $table->dropColumn(['foto_dokumentasi', 'latitude_hasil', 'longitude_hasil']);
        });
    }
};
