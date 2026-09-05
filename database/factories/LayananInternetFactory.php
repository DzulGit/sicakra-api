<?php

namespace Database\Factories;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use Illuminate\Database\Eloquent\Factories\Factory;

class LayananInternetFactory extends Factory
{
    protected $model = LayananInternet::class;

    public function definition(): array
    {
        return [
            'nomor_layanan' => 'LYN'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'pelanggan_id' => Pelanggan::factory(),
            'paket_internet_id' => PaketInternet::factory(),
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => now()->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LayananInternet $layanan) {
            if (! $layanan->permohonan_layanan_id) {
                $permohonan = PermohonanLayanan::factory()->create([
                    'pelanggan_id' => $layanan->pelanggan_id,
                    'paket_internet_id' => $layanan->paket_internet_id,
                    'status' => StatusPermohonanEnum::DIKONVERSI,
                ]);

                $layanan->setAttribute('permohonan_layanan_id', $permohonan->id);
            }
        });
    }
}