<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class PermohonanLayananFilter extends QueryFilter
{
    // ?status=DITERIMA atau ?status=DITERIMA,DIJADWALKAN
    protected function status(Builder $builder, string $nilai): void
    {
        $statuses = array_filter(explode(',', $nilai));
        if (count($statuses) > 1) {
            $builder->whereIn('status', $statuses);
        } else {
            $builder->where('status', $nilai);
        }
    }

    // ?jenis_permohonan=relokasi
    protected function jenisPermohonan(Builder $builder, string $nilai): void
    {
        $builder->where('jenis_permohonan', $nilai);
    }

    // ?pelanggan_id=5
    protected function pelangganId(Builder $builder, string $nilai): void
    {
        $builder->where('pelanggan_id', $nilai);
    }
}