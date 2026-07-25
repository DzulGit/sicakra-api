<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class PelangganFilter extends QueryFilter
{
    protected function cari(Builder $builder, string $nilai): void
    {
        $builder->where(function (Builder $q) use ($nilai) {
            $q->where('nama_lengkap', 'like', "%{$nilai}%")
              ->orWhere('nomor_pelanggan', 'like', "%{$nilai}%")
              ->orWhere('nik', 'like', "%{$nilai}%")
              ->orWhere('nomor_hp', 'like', "%{$nilai}%");
        });
    }
}
