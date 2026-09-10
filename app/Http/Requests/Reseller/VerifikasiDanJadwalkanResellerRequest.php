<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Verifikasi + jadwalkan dalam satu langkah versi reseller.
 * Sama seperti versi Operasional, minus teknisi (reseller satu dashboard,
 * penjadwalan teknisi tidak relevan).
 */
class VerifikasiDanJadwalkanResellerRequest extends FormRequest
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
            'harga_custom' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
