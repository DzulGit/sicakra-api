<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelanggan', function (Blueprint $table) {
            $table->id();
            // Nullable: baru diisi saat layanan pertama pelanggan resmi AKTIF
            $table->string('nomor_pelanggan')->unique()->nullable();
            $table->string('username')->unique()->nullable()->after('nomor_pelanggan');
            $table->string('nama_lengkap');
            $table->string('nik', 16)->unique();
            $table->string('nomor_hp')->unique();
            $table->string('email')->nullable();
            // Nullable: null selama pelanggan belum membuat password pertama kali
            $table->string('password')->nullable();
            $table->boolean('password_sudah_dibuat')->default(false);
            $table->string('foto_ktp');
            $table->string('foto_selfie_ktp');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pelanggan');
    }
};
