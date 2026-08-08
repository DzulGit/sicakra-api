<?php

namespace App\Http\Requests\PermohonanLayanan;

use Illuminate\Foundation\Http\FormRequest;

class HasilKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hasil' => ['required', 'in:selesai,kendala'],
            'catatan_kendala' => ['required_if:hasil,kendala', 'nullable', 'string'],
            // Dokumentasi wajib saat selesai: minimal 1, maksimal 3 foto
            'foto_dokumentasi' => ['required_if:hasil,selesai', 'array', 'min:1', 'max:3'],
            'foto_dokumentasi.*' => ['image', 'max:4096'],
            // Titik koordinat hasil kerja yang akurat dari lokasi
            'latitude_hasil' => ['required_if:hasil,selesai', 'numeric', 'between:-90,90'],
            'longitude_hasil' => ['required_if:hasil,selesai', 'numeric', 'between:-180,180'],
        ];
    }
}