<?php

namespace Database\Factories;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PermohonanLayananFactory extends Factory
{
    protected $model = PermohonanLayanan::class;

    public function definition(): array
    {
        return [
            'nomor_permohonan' => 'PMH'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'pelanggan_id' => Pelanggan::factory(),
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'paket_internet_id' => PaketInternet::factory(),
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
        ];
    }
}