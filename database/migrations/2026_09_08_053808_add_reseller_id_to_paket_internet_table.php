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
            $table->foreignId('reseller_id')->nullable()->constrained('admin')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paket_internet', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropColumn('reseller_id');
        });
    }
};
