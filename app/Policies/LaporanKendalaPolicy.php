<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\Pelanggan;

class LaporanKendalaPolicy
{
    public function viewAny(Admin|Pelanggan $user): bool
    {
        if ($user instanceof Admin) {
            return $user->memilikiPeran(
                PeranAdminEnum::OPERASIONAL,
                PeranAdminEnum::TEKNISI,
                PeranAdminEnum::SUPER_ADMIN,
            );
        }

        return true;
    }

    public function view(Admin|Pelanggan $user, LaporanKendala $laporan): bool
    {
        if ($user instanceof Admin) {
            return $this->viewAny($user);
        }

        return (int) $laporan->layananInternet->pelanggan_id === (int) $user->id;
    }

    public function create(Admin|Pelanggan $user): bool
    {
        if ($user instanceof Pelanggan) {
            return true;
        }

        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    /**
     * Terima laporan & teruskan ke Teknisi — khusus Operasional.
     */
    public function teruskanKeTeknisi(Admin $admin, LaporanKendala $laporan): bool
    {
        return $admin->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    /**
     * Isi hasil penanganan (status -> SELESAI) — khusus Teknisi.
     */
    public function selesaikan(Admin $admin, LaporanKendala $laporan): bool
    {
        return $admin->memilikiPeran(PeranAdminEnum::TEKNISI, PeranAdminEnum::SUPER_ADMIN);
    }

    /**
     * Tutup laporan setelah pelanggan dipastikan puas — khusus Operasional.
     */
    public function tutup(Admin $admin, LaporanKendala $laporan): bool
    {
        return $admin->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }
}