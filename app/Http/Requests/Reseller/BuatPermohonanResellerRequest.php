<?php

namespace App\Http\Requests\Reseller;

use App\Enums\JenisPermohonanEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reseller membuat permohonan atas nama pelanggan yang SUDAH ADA
 * (relokasi / ganti paket / tambah paket). Pemasangan baru TIDAK lewat sini —
 * reseller mendaftarkan pelanggan baru via daftarkanPelanggan (bypass).
 */
class BuatPermohonanResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pelanggan_id' => ['required', 'exists:pelanggan,id'],
            'jenis_permohonan' => ['required', Rule::in([
                JenisPermohonanEnum::RELOKASI->value,
                JenisPermohonanEnum::GANTI_PAKET->value,
                JenisPermohonanEnum::TAMBAH_PAKET->value,
            ])],
            'layanan_internet_id' => [
                'required_if:jenis_permohonan,relokasi',
                'required_if:jenis_permohonan,ganti_paket',
                'required_if:jenis_permohonan,tambah_paket',
                'nullable',
                'exists:layanan_internet,id',
            ],
            'tipe_paket' => ['nullable', Rule::in(['reguler', 'custom'])],
            'paket_internet_id' => ['nullable', 'exists:paket_internet,id'],
            'nama_paket_custom' => ['nullable', 'string'],
            'kecepatan_custom_mbps' => ['nullable', 'integer', 'min:1'],
            'harga_custom' => ['nullable', 'numeric', 'min:0'],
            'catatan_custom' => ['nullable', 'string'],
            'alasan' => ['nullable', 'string'],
            'alamat_pemasangan' => ['nullable', 'string'],
            'detail_alamat' => ['nullable', 'string'],
            'provinsi' => ['nullable', 'string', 'max:255'],
            'kota' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
