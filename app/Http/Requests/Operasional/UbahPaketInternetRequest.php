<?php

namespace App\Http\Requests\Operasional;

use Illuminate\Foundation\Http\FormRequest;

class UbahPaketInternetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_paket' => ['sometimes', 'string', 'max:255'],
            'kecepatan_mbps' => ['sometimes', 'integer', 'min:1'],
            'harga' => ['sometimes', 'numeric', 'min:0'],
            'jumlah_perangkat' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'status_aktif' => ['sometimes', 'boolean'],
        ];
    }
}
