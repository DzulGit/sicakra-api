<?php

namespace App\Http\Requests\PermohonanLayanan;

use Illuminate\Foundation\Http\FormRequest;

class TambahPermohonanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // proteksi via Policy di Controller
    }

    public function rules(): array
    {
        return [
            'pelanggan_id' => ['nullable', 'exists:pelanggan,id'],
            'jenis_permohonan' => ['required', 'in:pemasangan_baru,tambah_paket,ganti_paket,relokasi'],
            'layanan_internet_id' => [
                'required_if:jenis_permohonan,relokasi',
                'required_if:jenis_permohonan,ganti_paket',
                'required_if:jenis_permohonan,tambah_paket',
                'nullable', 'exists:layanan_internet,id',
            ],

            'tipe_paket' => ['required_if:jenis_permohonan,pemasangan_baru', 'nullable', 'in:reguler,custom'],
            'paket_internet_id' => ['nullable', 'exists:paket_internet,id'],
            'paket_internet_id_baru' => ['nullable', 'exists:paket_internet,id'],
            'nama_paket_custom' => ['nullable', 'string'],
            'kecepatan_custom_mbps' => ['nullable', 'integer', 'min:1'],
            'harga_custom' => ['nullable', 'numeric', 'min:0'],
            'catatan_custom' => ['nullable', 'string'],
            'alasan' => ['nullable', 'string'],

            'alamat_pemasangan' => ['nullable', 'string'],
            'detail_alamat' => ['nullable', 'string'],
            'rt' => ['nullable', 'string', 'max:3'],
            'rw' => ['nullable', 'string', 'max:3'],
            'kode_pos' => ['nullable', 'string', 'max:5'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
