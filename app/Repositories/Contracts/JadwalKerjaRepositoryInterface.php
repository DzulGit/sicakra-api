<?php

namespace App\Repositories\Contracts;

use App\Filters\JadwalKerjaFilter;
use App\Models\JadwalKerja;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface JadwalKerjaRepositoryInterface
{
    public function create(array $data): JadwalKerja;

    public function update(JadwalKerja $jadwal, array $data): JadwalKerja;

    public function find(int $id, array $with = []): ?JadwalKerja;

    /** Jadwal yang admin ini jadi anggota tim-nya (lewat pivot jadwal_kerja_teknisi), belum diisi hasil. */
    public function paginateMilikTeknisiBelumSelesai(int $adminId, int $perPage = 20): LengthAwarePaginator;

    /** Semua jadwal milik teknisi (riwayat), dengan filter. */
    public function paginateMilikTeknisi(int $adminId, JadwalKerjaFilter $filter, int $perPage = 20): LengthAwarePaginator;
}