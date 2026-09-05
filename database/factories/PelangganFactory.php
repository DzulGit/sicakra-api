<?php

namespace Database\Factories;

use App\Models\Pelanggan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PelangganFactory extends Factory
{
    protected $model = Pelanggan::class;

    public function definition(): array
    {
        return [
            'nama_lengkap' => fake()->name(),
            'nik' => fake()->unique()->numerify('################'),
            'nomor_hp' => fake()->unique()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'foto_ktp' => 'ktp/dummy.jpg',
            'foto_selfie_ktp' => 'selfie-ktp/dummy.jpg',
            'password_sudah_dibuat' => false,
        ];
    }

    /** Pelanggan yang sudah aktif & pernah buat password (skip alur login-pertama). */
    public function sudahAktif(): static
    {
        return $this->state(function () {
            $nomor = 'PLG'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

            return [
                'nomor_pelanggan' => $nomor,
                'username' => $nomor,
                'password' => 'password123',
                'password_sudah_dibuat' => true,
            ];
        });
    }
}
