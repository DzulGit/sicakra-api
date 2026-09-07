<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->after('id');
            $table->foreign('reseller_id')->references('id')->on('admin')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropColumn('reseller_id');
        });
    }
};
