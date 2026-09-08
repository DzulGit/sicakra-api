<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

class DaftarkanPelangganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nik' => ['required', 'string', 'size:16', 'unique:pelanggan,nik'],
            'nomor_hp' => ['required', 'string', 'max:20', 'unique:pelanggan,nomor_hp'],
            'email' => ['nullable', 'email', 'unique:pelanggan,email'],

            'alamat_pemasangan' => ['required', 'string'],
            'detail_alamat' => ['nullable', 'string'],
            'provinsi' => ['nullable', 'string', 'max:255'],
            'kota' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'paket_internet_id' => ['required', 'exists:paket_internet,id'],

            'foto_ktp' => ['nullable', 'image', 'max:2048'],
            'foto_selfie_ktp' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nik.unique' => 'NIK sudah terdaftar.',
            'nomor_hp.unique' => 'Nomor HP sudah terdaftar.',
            'email.unique' => 'Email sudah terdaftar.',
        ];
    }
}