<?php

namespace App\Http\Requests\Operasional;

use Illuminate\Foundation\Http\FormRequest;

class SimpanResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via middleware peran:operasional,super_admin
    }

    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admin,email'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
