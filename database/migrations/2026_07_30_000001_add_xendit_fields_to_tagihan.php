<?php

use App\Enums\StatusPembayaranEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->unsignedTinyInteger('jumlah_bulan')->default(1)->after('total_tagihan');
            $table->string('xendit_invoice_status')->nullable()->after('xendit_invoice_url');
            $table->timestamp('xendit_invoice_expires_at')->nullable()->after('xendit_invoice_status');
            $table->unsignedTinyInteger('xendit_invoice_retry_count')->default(0)->after('xendit_invoice_expires_at');
        });

        Schema::table('tagihan', function (Blueprint $table) {
            $table->index('xendit_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropColumn([
                'jumlah_bulan',
                'xendit_invoice_status',
                'xendit_invoice_expires_at',
                'xendit_invoice_retry_count',
            ]);
        });
    }
};
