<?php

namespace App\Rules;

use App\Models\Pelanggan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NikUnik implements ValidationRule
{
    public function __construct(
        private readonly ?int $resellerId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pelanggan = Pelanggan::query()
            ->where('nik_hash', hash('sha256', $value));

        if ($this->resellerId === null) {
            $pelanggan->whereNull('reseller_id');
        } else {
            $pelanggan->where('reseller_id', $this->resellerId);
        }

        if ($pelanggan->exists()) {
            $fail('NIK sudah terdaftar.');
        }
    }
}
