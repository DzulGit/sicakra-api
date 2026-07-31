<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Pelanggan;
use Illuminate\Support\Facades\Hash;

class PelangganSeeder extends Seeder
{
    public function run(): void
    {
        Pelanggan::create([
            'nama_lengkap' => 'Budi Test',
            'username' => 'budi',
            'email' => 'budi@test.com',
            'password' => Hash::make('budi'),
            'nomor_hp' => '081234567899',
            'nik' => '3320011223340001',
            'foto_ktp' => 'default_ktp.jpg',
        ]);
    }
}
