<?php

namespace App\Http\Requests\LaporanKendala;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuatLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi tambahan lewat Policy di Controller
    }

    public function rules(): array
    {
        return [
            'layanan_internet_id' => [
                'required',
                Rule::exists('layanan_internet', 'id')->where('pelanggan_id', $this->user()->id),
            ],
            'kategori_kendala' => ['required', 'string', 'max:255'],
            'deskripsi' => ['required', 'string'],
            'foto' => ['nullable', 'array'],
            'foto.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}