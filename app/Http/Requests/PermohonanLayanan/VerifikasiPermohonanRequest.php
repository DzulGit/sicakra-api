<?php

namespace App\Http\Requests\PermohonanLayanan;

use Illuminate\Foundation\Http\FormRequest;

class VerifikasiPermohonanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:PERLU_REVISI,DITERIMA,DITOLAK'],
            'catatan' => ['required_if:status,PERLU_REVISI,DITOLAK', 'nullable', 'string'],
            'harga_custom' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}