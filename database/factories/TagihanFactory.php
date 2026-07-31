<?php

namespace Database\Factories;

use App\Enums\StatusPembayaranEnum;
use App\Models\LayananInternet;
use App\Models\Tagihan;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagihanFactory extends Factory
{
    protected $model = Tagihan::class;

    public function definition(): array
    {
        return [
            'nomor_tagihan' => 'INV-' . fake()->unique()->numerify('#######'),
            'layanan_internet_id' => LayananInternet::factory(),
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'nama_paket_snapshot' => fake()->words(3, true),
            'kecepatan_snapshot_mbps' => fake()->numberBetween(10, 100),
            'harga_snapshot' => 150000,
            'total_tagihan' => 150000,
            'tanggal_jatuh_tempo' => now()->addDays(15)->toDateString(),
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ];
    }
}
