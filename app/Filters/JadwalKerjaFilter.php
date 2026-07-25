<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class JadwalKerjaFilter extends QueryFilter
{
    protected function hasil(Builder $builder, string $nilai): void
    {
        if ($nilai === 'belum') {
            $builder->whereNull('hasil');
        } else {
            $builder->where('hasil', $nilai);
        }
    }
}
