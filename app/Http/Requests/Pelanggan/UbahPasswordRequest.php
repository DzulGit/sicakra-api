<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;

class UbahPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via middleware auth:sanctum di route
    }

    public function rules(): array
    {
        // password_lama hanya wajib kalau pelanggan sudah pernah membuat
        // password sendiri. Kalau masih pakai password default (=
        // nomor_pelanggan, password_sudah_dibuat masih false), tidak perlu
        // verifikasi password lama sama sekali.
        $wajibPasswordLama = (bool) $this->user()->password_sudah_dibuat;

        return [
            'password_lama' => [$wajibPasswordLama ? 'required' : 'sometimes', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_lama.required' => 'Password lama wajib diisi.',
        ];
    }
}