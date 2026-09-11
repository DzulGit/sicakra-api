<?php

namespace App\Repositories\Eloquent;

use App\Filters\TagihanFilter;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TagihanRepository implements TagihanRepositoryInterface
{
    public function create(array $data): Tagihan
    {
        return Tagihan::create($data);
    }

    public function find(int $id, array $with = []): ?Tagihan
    {
        return Tagihan::with($with)->find($id);
    }

    public function paginateSemua(TagihanFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = Tagihan::query()
            ->with('layananInternet.pelanggan')
            ->whereHas('layananInternet.pelanggan', function ($query) {$query->whereNull('reseller_id');})
            ->latest();

        return $filter->apply($query)->paginate($perPage);
    }

    public function paginateUntukPelanggan(int $pelangganId, TagihanFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = Tagihan::query()
            ->whereHas('layananInternet', fn ($q) => $q->where('pelanggan_id', $pelangganId))
            ->latest();

        return $filter->apply($query)->paginate($perPage);
    }
}