<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            // Tetap dipertahankan untuk kompatibilitas data lama.
            // Pembayaran baru tidak wajib terikat langsung ke satu tagihan.
            $table->foreignId('tagihan_id')
                ->nullable()
                ->change();

            // Pemilik transaksi pembayaran.
            $table->foreignId('pelanggan_id')
                ->nullable()
                ->after('tagihan_id')
                ->constrained('pelanggan')
                ->nullOnDelete();

            // Informasi gateway pembayaran yang bersifat generic.
            $table->string('provider')->nullable()->after('metode_pembayaran');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->string('provider_external_id')->nullable()->after('provider_reference');
            $table->text('payment_url')->nullable()->after('provider_external_id');
            $table->string('provider_status')->nullable()->after('payment_url');
            $table->timestamp('provider_expires_at')->nullable()->after('provider_status');

            $table->index('provider_reference');
            $table->index('provider_external_id');
        });

        Schema::create('pembayaran_tagihan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pembayaran_id')
                ->constrained('pembayaran')
                ->cascadeOnDelete();

            $table->foreignId('tagihan_id')
                ->constrained('tagihan')
                ->cascadeOnDelete();

            $table->decimal('jumlah_dialokasikan', 12, 2);

            $table->timestamps();

            $table->unique(
                ['pembayaran_id', 'tagihan_id'],
                'pembayaran_tagihan_unique'
            );

            $table->index('tagihan_id');
        });

        Schema::create('mutasi_saldo_kredit', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pelanggan_id')
                ->constrained('pelanggan')
                ->cascadeOnDelete();

            $table->foreignId('pembayaran_id')
                ->nullable()
                ->constrained('pembayaran')
                ->nullOnDelete();

            $table->foreignId('tagihan_id')
                ->nullable()
                ->constrained('tagihan')
                ->nullOnDelete();

            // kredit = uang lebih yang masuk
            // pemakaian = saldo kredit yang digunakan
            $table->string('jenis');

            $table->decimal('jumlah', 12, 2);

            $table->text('keterangan')->nullable();

            $table->timestamps();

            $table->index(['pelanggan_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_saldo_kredit');
        Schema::dropIfExists('pembayaran_tagihan');

        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropForeign(['pelanggan_id']);

            $table->dropColumn([
                'pelanggan_id',
                'provider',
                'provider_reference',
                'provider_external_id',
                'payment_url',
                'provider_status',
                'provider_expires_at',
            ]);

            $table->foreignId('tagihan_id')
                ->nullable(false)
                ->change();
        });
    }
};
