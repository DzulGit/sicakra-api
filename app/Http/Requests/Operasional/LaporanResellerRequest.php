<?php

namespace App\Http\Requests\Operasional;

use App\Enums\PeranAdminEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaporanResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via middleware peran:operasional,super_admin
    }

    public function rules(): array
    {
        return [
            'reseller_id' => [
                'nullable',
                'integer',
                Rule::exists('admin', 'id')->where(
                    fn (Builder $q) => $q->where('peran', PeranAdminEnum::RESELLER->value),
                ),
            ],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}