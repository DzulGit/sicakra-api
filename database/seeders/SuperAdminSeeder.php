<?php

namespace Database\Seeders;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Super Admin HANYA dibuat lewat seeder ini, tidak lewat form/API publik.
     * Ganti SUPER_ADMIN_EMAIL & SUPER_ADMIN_PASSWORD di .env sebelum dijalankan.
     */
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'admin@sicakra.com')],
            [
                'nama_lengkap' => 'Super Admin',
                'password' => env('SUPER_ADMIN_PASSWORD', 'Admins1cakra'),
                'peran' => PeranAdminEnum::SUPER_ADMIN,
                'status_aktif' => true,
            ]
        );
    }
}