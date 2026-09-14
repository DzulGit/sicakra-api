<?php

namespace App\Http\Requests\Operasional;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuatPelangganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via middleware peran:operasional,super_admin
    }

    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nik' => [
                'required', 'string', 'size:16',
                Rule::unique('pelanggan', 'nik')->whereNull('reseller_id'),
            ],
            'nomor_hp' => [
                'required', 'string', 'max:20',
                Rule::unique('pelanggan', 'nomor_hp')->whereNull('reseller_id'),
            ],
            'email' => [
                'required', 'email',
                Rule::unique('pelanggan', 'email')->whereNull('reseller_id'),
            ],

            'alamat_pemasangan' => ['required', 'string'],
            'detail_alamat' => ['nullable', 'string'],
            'provinsi' => ['nullable', 'string', 'max:255'],
            'kota' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'tipe_paket' => ['required', 'in:reguler,custom'],
            'paket_internet_id' => ['required_if:tipe_paket,reguler', 'nullable', 'exists:paket_internet,id'],
            'nama_paket_custom' => ['required_if:tipe_paket,custom', 'nullable', 'string'],
            'kecepatan_custom_mbps' => ['required_if:tipe_paket,custom', 'nullable', 'integer', 'min:1'],
            'catatan_custom' => ['nullable', 'string'],

            'foto_ktp' => ['required', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah terdaftar. Gunakan email lain.',
            'foto_ktp.required' => 'Foto KTP wajib diunggah.',
            'nik.unique' => 'NIK sudah terdaftar.',
            'nomor_hp.unique' => 'Nomor HP sudah terdaftar.',
        ];
    }
}