<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class TagihanFilter extends QueryFilter
{
    // ?status_pembayaran=belum_bayar
    protected function statusPembayaran(Builder $builder, string $nilai): void
    {
        $builder->where('status_pembayaran', $nilai);
    }

    // ?periode_bulan=7
    protected function periodeBulan(Builder $builder, string $nilai): void
    {
        $builder->where('periode_bulan', $nilai);
    }

    // ?periode_tahun=2026
    protected function periodeTahun(Builder $builder, string $nilai): void
    {
        $builder->where('periode_tahun', $nilai);
    }

    // ?search=budi → cocok NIK / nama / nomor pelanggan (case-insensitive)
    protected function search(Builder $builder, string $nilai): void
    {
        $nilai = strtolower($nilai);

        $builder->whereHas('layananInternet.pelanggan', function ($q) use ($nilai) {
            $q->where(function ($sq) use ($nilai) {
                $sq->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$nilai}%"])
                    ->orWhereRaw('LOWER(nik) LIKE ?', ["%{$nilai}%"])
                    ->orWhereRaw('LOWER(nomor_pelanggan) LIKE ?', ["%{$nilai}%"]);
            });
        });
    }
}