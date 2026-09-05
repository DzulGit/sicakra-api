<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class LaporanKendalaFilter extends QueryFilter
{
    // ?status=diproses
    protected function status(Builder $builder, string $nilai): void
    {
        $builder->where('status', $nilai);
    }

    // ?kategori_kendala=Jaringan+Putus
    protected function kategoriKendala(Builder $builder, string $nilai): void
    {
        $builder->whereRaw('LOWER(kategori_kendala) LIKE ?', ['%'.strtolower($nilai).'%']);
    }
}
