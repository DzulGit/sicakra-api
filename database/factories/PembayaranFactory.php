<?php

namespace Database\Factories;

use App\Enums\StatusTransaksiEnum;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PembayaranFactory extends Factory
{
    protected $model = Pembayaran::class;

    public function definition(): array
    {
        return [
            'tagihan_id' => Tagihan::factory(),
            'metode_pembayaran' => fake()->randomElement(['QRIS', 'BCA', 'MANDIRI']),
            'jumlah_dibayar' => 150000,
            'referensi_xendit' => 'inv-' . fake()->uuid(),
            'status' => StatusTransaksiEnum::PENDING,
        ];
    }
}
