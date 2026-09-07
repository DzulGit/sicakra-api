<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\PaketInternet;

class PaketInternetPolicy
{
    public function viewAny(Admin $user): bool
    {
        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function view(Admin $user, PaketInternet $paketInternet): bool
    {
        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function create(Admin $user): bool
    {
        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function update(Admin $user, PaketInternet $paketInternet): bool
    {
        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }

    public function delete(Admin $user, PaketInternet $paketInternet): bool
    {
        return $user->memilikiPeran(PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN);
    }
}
