<?php

namespace App\Enums;

enum JenisPermohonanEnum: string
{
    case PEMASANGAN_BARU = 'pemasangan_baru';
    case TAMBAH_PAKET = 'tambah_paket';
    case GANTI_PAKET = 'ganti_paket';
    case RELOKASI = 'relokasi';

    public function label(): string
    {
        return match ($this) {
            self::PEMASANGAN_BARU => 'Pemasangan Baru',
            self::TAMBAH_PAKET => 'Tambah Paket',
            self::GANTI_PAKET => 'Ganti Paket',
            self::RELOKASI => 'Relokasi',
        };
    }
}