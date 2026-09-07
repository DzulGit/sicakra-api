<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;

class LayananInternetPolicy
{
    public function viewAny(Admin|Pelanggan $user): bool
    {
        return true;
    }

    public function view(Admin|Pelanggan $user, LayananInternet $layanan): bool
    {
        if ($user instanceof Admin) {
            return true;
        }

        return (int) $layanan->pelanggan_id === (int) $user->id;
    }

    /**
     * Upgrade/downgrade paket & perubahan lain di luar hasil konversi permohonan
     * — khusus Operasional.
     */
    public function update(Admin $admin, LayananInternet $layanan): bool
    {
        return $admin->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function delete(Admin $admin, LayananInternet $layanan): bool
    {
        return $admin->peran === PeranAdminEnum::SUPER_ADMIN;
    }
}