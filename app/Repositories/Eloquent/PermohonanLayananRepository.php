<?php

namespace App\Repositories\Eloquent;

use App\Filters\PermohonanLayananFilter;
use App\Models\PermohonanLayanan;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PermohonanLayananRepository implements PermohonanLayananRepositoryInterface
{
    public function create(array $data): PermohonanLayanan
    {
        return PermohonanLayanan::create($data);
    }

    public function update(PermohonanLayanan $permohonan, array $data): PermohonanLayanan
    {
        $permohonan->update($data);

        return $permohonan->fresh();
    }

    public function find(int $id, array $with = []): ?PermohonanLayanan
    {
        return PermohonanLayanan::query()
            ->whereHas('pelanggan', function ($query) {
                $query->whereNull('reseller_id');
            })
            ->with($with)
            ->find($id);
    }

    public function paginate(PermohonanLayananFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = PermohonanLayanan::query()
            ->whereHas('pelanggan', function ($query) {
                $query->whereNull('reseller_id');
            })
            ->with('pelanggan')
            ->latest();

        return $filter->apply($query)->paginate($perPage);
    }
}