<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class PelangganFilter extends QueryFilter
{
    protected function cari(Builder $builder, string $nilai): void
    {
        // LOWER + strtolower → case-insensitive (pola yang sama dipakai TagihanFilter).
        // `like` mentah di PostgreSQL ternyata case-sensitive.
        $nilai = strtolower($nilai);

        $builder->where(function (Builder $q) use ($nilai) {
            $q->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$nilai}%"])
                ->orWhereRaw('LOWER(nomor_pelanggan) LIKE ?', ["%{$nilai}%"])
                ->orWhere('nik_hash', hash('sha256', $nilai))
                ->orWhereRaw('LOWER(nomor_hp) LIKE ?', ["%{$nilai}%"]);
        });
    }

    protected function reseller(Builder $builder, string $nilai): void
    {
        $builder->where('reseller_id', (int) $nilai);
    }

    protected function jenis(Builder $builder, string $nilai): void
    {
        if ($nilai === 'aktif') {
            $builder
                ->whereNotNull('nomor_pelanggan')
                ->whereHas('layananInternet', function (Builder $query) {
                    $query
                        ->where('status', 'aktif')
                        ->whereHas('tagihan');
                });
        }
    }
}
