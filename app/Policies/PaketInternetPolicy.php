<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\PaketInternet;

class PaketInternetPolicy
{
    public function viewAny(Admin $user): bool
    {
        return in_array($user->peran, [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN], true);
    }

    public function view(Admin $user, PaketInternet $paketInternet): bool
    {
        return in_array($user->peran, [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN], true);
    }

    public function create(Admin $user): bool
    {
        return in_array($user->peran, [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN], true);
    }

    public function update(Admin $user, PaketInternet $paketInternet): bool
    {
        return in_array($user->peran, [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN], true);
    }

    public function delete(Admin $user, PaketInternet $paketInternet): bool
    {
        return in_array($user->peran, [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN], true);
    }
}
