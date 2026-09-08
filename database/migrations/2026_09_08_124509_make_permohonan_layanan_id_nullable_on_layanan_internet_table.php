<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->foreignId('permohonan_layanan_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('layanan_internet', function (Blueprint $table) {
            $table->foreignId('permohonan_layanan_id')
                ->nullable(false)
                ->change();
        });
    }
};