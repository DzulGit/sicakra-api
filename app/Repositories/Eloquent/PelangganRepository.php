<?php

namespace App\Repositories\Eloquent;

use App\Filters\PelangganFilter;
use App\Models\Pelanggan;
use App\Repositories\Contracts\PelangganRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PelangganRepository implements PelangganRepositoryInterface
{
    public function find(int $id, array $with = []): ?Pelanggan
    {
        return Pelanggan::with($with)->find($id);
    }

    public function paginate(PelangganFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = Pelanggan::query()->with('layananInternet.paketInternet')->latest();
        return $filter->apply($query)->paginate($perPage);
    }
}
