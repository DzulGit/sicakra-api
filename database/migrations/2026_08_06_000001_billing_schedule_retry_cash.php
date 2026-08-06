<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TAHAP 1: Tanggal penagihan per pelanggan (1-31). Default 20
        // mempertahankan perilaku lama, lalu Admin Keuangan bebas mengubahnya.
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->unsignedTinyInteger('tanggal_tagihan')->default(20)->after('password_sudah_dibuat');
        });

        // TAHAP 4: Batas ubah/regenerate jumlah bulan tagihan (maks 3x per invoice).
        // Terpisah dari xendit_invoice_retry_count (limit buat ulang link Xendit).
        Schema::table('tagihan', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('xendit_invoice_retry_count');
        });

        // TAHAP 2: Catat admin penerima untuk pembayaran tunai di kantor.
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->string('dibayar_oleh')->nullable()->after('metode_pembayaran');
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn('dibayar_oleh');
        });

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });

        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropColumn('tanggal_tagihan');
        });
    }
};
