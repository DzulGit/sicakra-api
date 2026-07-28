<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('paket_internet', function (Blueprint $table) {
            $table->unsignedTinyInteger('jumlah_perangkat')->default(5)->after('harga');
        });
    }

    public function down(): void
    {
        Schema::table('paket_internet', function (Blueprint $table) {
            $table->dropColumn('jumlah_perangkat');
        });
    }
};
