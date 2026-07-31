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
        Schema::table('riwayat_relokasi', function (Blueprint $table) {
            $columnsToDrop = ['rt_lama', 'rw_lama', 'kode_pos_lama', 'rt_baru', 'rw_baru', 'kode_pos_baru'];
            
            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('riwayat_relokasi', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
