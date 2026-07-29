<?php

namespace App\Repositories\Eloquent;

use App\Models\LayananInternet;
use App\Repositories\Contracts\LayananInternetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LayananInternetRepository implements LayananInternetRepositoryInterface
{
    public function create(array $data): LayananInternet
    {
        return LayananInternet::create($data);
    }

    public function update(LayananInternet $layanan, array $data): LayananInternet
    {
        $layanan->update($data);

        return $layanan->fresh();
    }

    public function find(int $id, array $with = []): ?LayananInternet
    {
        return LayananInternet::with($with)->find($id);
    }

    public function paginateUntukPelanggan(int $pelangganId, int $perPage = 20): LengthAwarePaginator
    {
        return LayananInternet::where('pelanggan_id', $pelangganId)
            ->with('paketInternet')
            ->withCount('perangkat')
            ->latest()
            ->paginate($perPage);
    }
}