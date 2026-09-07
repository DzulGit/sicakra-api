<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class SimpanAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admin,email'],
            'password' => ['required', 'string', 'min:8'],
            // super_admin SENGAJA tidak diizinkan dibuat lewat sini — hanya lewat SuperAdminSeeder
            'peran' => ['required', 'in:operasional,teknisi,keuangan,reseller'],
        ];
    }
}