<?php

namespace App\Repositories\Eloquent;

use App\Enums\StatusPermohonanEnum;
use App\Filters\PermohonanLayananFilter;
use App\Models\PermohonanLayanan;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
            ->with('pelanggan');

        $this->urutkanTahap($query);

        return $filter->apply($query)->paginate($perPage);
    }

    /**
     * Antrean kerja operasional: status per tahap kerja, bukan created_at murni.
     * Pakai CASE di SQL supaya paginasi tetap benar (urutan di PHP terpotong
     * paginator). Bindings dipakai, bukan interpolasi string.
     */
    private function urutkanTahap(Builder $query): void
    {
        $tahapan = StatusPermohonanEnum::urutanTahap();

        $cases = '';
        $bindings = [];

        foreach (array_values($tahapan) as $index => $status) {
            $cases .= ' WHEN ? THEN '.$index;
            $bindings[] = $status;
        }

        // ELSE = jumlah tahap: status di luar daftar ini tetap tampil, di paling bawah.
        $query->orderByRaw(
            'CASE status'.$cases.' ELSE '.count($tahapan).' END',
            $bindings
        )->latest('created_at');
    }
}