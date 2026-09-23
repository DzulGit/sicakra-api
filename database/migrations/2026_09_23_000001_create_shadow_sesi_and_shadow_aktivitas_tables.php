<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_sesi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admin')->cascadeOnDelete();
            $table->foreignId('reseller_id')->constrained('admin')->cascadeOnDelete();
            // Kode penukaran sekali pakai — hash-nya disimpan, token asli tidak pernah lewat URL.
            $table->char('kode_hash', 64)->nullable()->unique();
            $table->timestamp('kode_kedaluwarsa_pada')->nullable();
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestamp('token_kedaluwarsa_pada')->nullable();
            $table->timestamp('diakhiri_pada')->nullable();
            $table->foreignId('diakhiri_oleh')->nullable()->constrained('admin')->nullOnDelete();
            $table->timestamps();

            $table->index(['admin_id', 'reseller_id']);
        });

        Schema::create('shadow_aktivitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shadow_sesi_id')->constrained('shadow_sesi')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admin')->cascadeOnDelete();
            $table->foreignId('reseller_id')->constrained('admin')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'reseller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_aktivitas');
        Schema::dropIfExists('shadow_sesi');
    }
};