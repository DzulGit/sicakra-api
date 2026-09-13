<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            if (!Schema::hasColumn('pembayaran', 'dibayar_oleh')) {
                $table->string('dibayar_oleh')->nullable()->after('provider_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn('dibayar_oleh');
        });
    }
};