<?php

namespace App\Repositories\Contracts;

use App\Filters\PelangganFilter;
use App\Models\Pelanggan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PelangganRepositoryInterface
{
    public function find(int $id, array $with = []): ?Pelanggan;

    public function paginate(PelangganFilter $filter, int $perPage = 20): LengthAwarePaginator;
}
