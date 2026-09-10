<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

/** Jadwalkan (ulang) kerja versi reseller — hanya tanggal, tanpa teknisi. */
class JadwalkanResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_kerja' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
