<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;

class ResellerPolicy
{
    /** Manajemen & monitoring reseller: hanya Admin Operasional / Super Admin. */
    private function diizinkan(Admin $admin): bool
    {
        return $admin->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->diizinkan($admin);
    }

    public function view(Admin $admin, Admin $reseller): bool
    {
        if (! $this->diizinkan($admin)) {
            return false;
        }

        return $reseller->peran === PeranAdminEnum::RESELLER;
    }

    public function create(Admin $admin): bool
    {
        return $this->diizinkan($admin);
    }

    /**
     * Memantau daftar pelanggan milik reseller (read-only). Tidak ada ability
     * tulis (update/destroy) di policy ini sehingga admin hanya bisa MEMBACA.
     */
    public function lihatPelanggan(Admin $admin, Admin $reseller): bool
    {
        return $this->view($admin, $reseller);
    }
}
