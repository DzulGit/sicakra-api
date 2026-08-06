<?php

namespace App\Http\Requests\Operasional;

use Illuminate\Foundation\Http\FormRequest;

class SimpanPaketInternetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_paket' => ['required', 'string', 'max:255'],
            'kecepatan_mbps' => ['required', 'integer', 'min:1'],
            'harga' => ['required', 'numeric', 'min:0'],
            'jumlah_perangkat' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'status_aktif' => ['sometimes', 'boolean'],
            'promo_gratis_bulan' => ['sometimes', 'integer', 'min:0', 'max:24'],
        ];
    }
}
