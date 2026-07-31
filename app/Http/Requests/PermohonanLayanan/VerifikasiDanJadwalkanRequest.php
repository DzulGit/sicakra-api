<?php

namespace App\Http\Requests\PermohonanLayanan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifikasiDanJadwalkanRequest extends FormRequest
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
            'tanggal_kerja' => ['required_if:status,DITERIMA', 'nullable', 'date', 'after_or_equal:today'],
            'teknisi_ids' => ['required_if:status,DITERIMA', 'nullable', 'array', 'min:1'],
            'teknisi_ids.*' => [
                Rule::exists('admin', 'id')->where('peran', 'teknisi'),
            ],
            'tim_teknisi_id' => ['nullable', 'exists:tim_teknisi,id'],
            'harga_custom' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
