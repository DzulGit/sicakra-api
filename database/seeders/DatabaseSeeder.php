<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Project ini tidak punya tabel `users` bawaan Laravel — user dipecah
     * jadi `admin` dan `pelanggan`. Satu-satunya akun yang wajib ada dari
     * seeder adalah Super Admin, dibuat lewat SuperAdminSeeder (baca kredensial
     * dari .env: SUPER_ADMIN_EMAIL & SUPER_ADMIN_PASSWORD).
     */
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            PaketInternetSeeder::class,
            PelangganSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
