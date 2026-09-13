<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Status 'kedaluwarsa' adalah nilai enum lama yang sudah dihapus
     * (sistem jatuh tempo dibubarkan). Sisa record ini membuat eager-load
     * tagihan gagal di-cast -> 500. Kedaluwarsa = invoice Xendit lama
     * kadaluwarsa, tagihan tetap wajib dibayar -> dipetakan ke 'belum_bayar'.
     */
    public function up(): void
    {
        DB::table('tagihan')
            ->where('status_pembayaran', 'kedaluwarsa')
            ->update(['status_pembayaran' => 'belum_bayar']);
    }

    public function down(): void
    {
        // Tidak bisa dikembalikan; nilai 'kedaluwarsa' sudah bukan status valid.
    }
};