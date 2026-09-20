<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enkripsi nilai NIK yang sudah ada + tambah kolom hash.
        // Urutannya penting: ubah tipe kolom dulu (encrypted lebih panjang
        // dari 16 karakter), lalu tambah nik_hash, baru enkripsi data.
        Schema::table('pelanggan', function (Blueprint $table) {
            $table->string('nik', 255)->change();
            $table->string('nik_hash', 64)->after('nik')->nullable();
        });

        DB::table('pelanggan')->orderBy('id')->each(function (stdClass $row): void {
            if ($row->nik === null || $row->nik === '') {
                return;
            }

            DB::table('pelanggan')
                ->where('id', $row->id)
                ->update([
                    'nik' => Crypt::encryptString($row->nik),
                    'nik_hash' => hash('sha256', $row->nik),
                ]);
        });

        // Unik NIK kini harus dijamin lewat nik_hash (hash deterministik),
        // karena nilai nik sudah berupa ciphertext acak per penyimpanan.
        DB::statement('DROP INDEX IF EXISTS pelanggan_nik_reseller_unique');
        DB::statement('DROP INDEX IF EXISTS pelanggan_nik_sistem_utama_unique');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_hash_reseller_unique ON pelanggan (nik_hash, reseller_id)');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_hash_sistem_utama_unique ON pelanggan (nik_hash) WHERE reseller_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pelanggan_nik_hash_reseller_unique');
        DB::statement('DROP INDEX IF EXISTS pelanggan_nik_hash_sistem_utama_unique');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_reseller_unique ON pelanggan (nik, reseller_id)');
        DB::statement('CREATE UNIQUE INDEX pelanggan_nik_sistem_utama_unique ON pelanggan (nik) WHERE reseller_id IS NULL');

        DB::table('pelanggan')->orderBy('id')->each(function (stdClass $row): void {
            if ($row->nik === null || $row->nik === '') {
                return;
            }

            DB::table('pelanggan')
                ->where('id', $row->id)
                ->update(['nik' => Crypt::decryptString($row->nik)]);
        });

        Schema::table('pelanggan', function (Blueprint $table) {
            $table->dropColumn('nik_hash');
            $table->string('nik', 16)->change();
        });
    }
};
