<?php

namespace App\Http\Requests\Pelanggan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UbahUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via middleware auth:sanctum di route
    }

    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:4',
                'max:30',
                'regex:/^[a-zA-Z0-9_.]+$/',
                Rule::unique('pelanggan', 'username')->ignore($this->user()->id),
            ],
        ];
    }
}