<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->boolean('pakai_saldo_kredit')->default(false)->after('dibayar_oleh');
            $table->text('tagihan_terpilih')->nullable()->after('pakai_saldo_kredit');
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn(['pakai_saldo_kredit', 'tagihan_terpilih']);
        });
    }
};