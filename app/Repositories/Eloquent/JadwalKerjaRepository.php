<?php

namespace App\Repositories\Eloquent;

use App\Filters\JadwalKerjaFilter;
use App\Models\JadwalKerja;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class JadwalKerjaRepository implements JadwalKerjaRepositoryInterface
{
    public function create(array $data): JadwalKerja
    {
        return JadwalKerja::create($data);
    }

    public function update(JadwalKerja $jadwal, array $data): JadwalKerja
    {
        $jadwal->update($data);

        return $jadwal->fresh();
    }

    public function find(int $id, array $with = []): ?JadwalKerja
    {
        return JadwalKerja::with($with)->find($id);
    }

    public function paginateMilikTeknisiBelumSelesai(int $adminId, int $perPage = 20): LengthAwarePaginator
    {
        return JadwalKerja::whereHas('teknisi', fn ($q) => $q->where('admin_id', $adminId))
            ->whereNull('hasil')
            ->with(['permohonanLayanan.pelanggan', 'teknisi', 'timTeknisi'])
            ->orderBy('tanggal_kerja')
            ->paginate($perPage);
    }

    public function paginateMilikTeknisi(int $adminId, JadwalKerjaFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = JadwalKerja::whereHas('teknisi', fn ($q) => $q->where('admin_id', $adminId))
            ->with(['permohonanLayanan.pelanggan', 'teknisi', 'timTeknisi'])
            ->orderBy('tanggal_kerja');

        return $filter->apply($query)->paginate($perPage);
    }
}