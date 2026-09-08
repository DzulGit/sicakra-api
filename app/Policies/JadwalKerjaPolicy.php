<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;

class JadwalKerjaPolicy
{
    /** Super Admin bisa lihat semua; Teknisi cuma yang dia jadi anggota tim-nya. */
    public function view(Admin $admin, JadwalKerja $jadwal): bool
    {
        if ($admin->peran === PeranAdminEnum::SUPER_ADMIN) {
            return true;
        }

        return $jadwal->teknisi()->where('admin_id', $admin->id)->exists();
    }

    public function isiHasil(Admin $admin, JadwalKerja $jadwal): bool
    {
        return $this->view($admin, $jadwal);
    }
}
