<?php

namespace Database\Factories;

use App\Enums\StatusTransaksiEnum;
use App\Models\Pembayaran;
use App\Models\Pelanggan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PembayaranFactory extends Factory
{
    protected $model = Pembayaran::class;

    public function definition(): array
    {
        return [
            'tagihan_id' => null,
            'pelanggan_id' => Pelanggan::factory(),
            'metode_pembayaran' => fake()->randomElement(['QRIS', 'BCA', 'MANDIRI']),
            'provider' => 'xendit',
            'provider_reference' => null,
            'provider_external_id' => null,
            'payment_url' => null,
            'provider_status' => null,
            'provider_expires_at' => null,
            'dibayar_oleh' => null,
            'jumlah_dibayar' => 150000,
            'referensi_xendit' => null,
            'status' => StatusTransaksiEnum::PENDING,
            'payload_webhook' => null,
            'dibayar_pada' => null,
        ];
    }
}