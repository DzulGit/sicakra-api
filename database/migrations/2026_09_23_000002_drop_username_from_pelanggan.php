<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login pelanggan hanya lewat nomor_pelanggan — kolom username tidak
        // dipakai lagi di sistem. Unique index di-drop eksplisit dulu karena
        // SQLite tidak menurunkan index otomatis saat kolom di-drop.
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropUnique('pelanggan_username_unique');
            $table->dropColumn('username');
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('username')->unique()->nullable();
        });
    }
};