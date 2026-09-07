<?php

namespace Database\Factories;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'nama_lengkap' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'peran' => fake()->randomElement([
                PeranAdminEnum::OPERASIONAL,
                PeranAdminEnum::TEKNISI,
                PeranAdminEnum::KEUANGAN,
            ]),
            'status_aktif' => true,
        ];
    }

    public function operasional(): static
    {
        return $this->state(['peran' => PeranAdminEnum::OPERASIONAL]);
    }

    public function teknisi(): static
    {
        return $this->state(['peran' => PeranAdminEnum::TEKNISI]);
    }

    public function keuangan(): static
    {
        return $this->state(['peran' => PeranAdminEnum::KEUANGAN]);
    }

    public function superAdmin(): static
    {
        return $this->state(['peran' => PeranAdminEnum::SUPER_ADMIN]);
    }

    public function reseller(): static
    {
        return $this->state(['peran' => PeranAdminEnum::RESELLER]);
    }
}