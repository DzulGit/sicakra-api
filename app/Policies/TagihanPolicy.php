<?php

namespace App\Policies;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusPembayaranEnum;
use App\Models\Admin;
use App\Models\Pelanggan;
use App\Models\Tagihan;

class TagihanPolicy
{
    public function viewAny(Admin|Pelanggan $user): bool
    {
        if ($user instanceof Admin) {
            return in_array($user->peran, [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN], true);
        }

        return true; // pelanggan boleh lihat daftar tagihan miliknya sendiri
    }

    public function view(Admin|Pelanggan $user, Tagihan $tagihan): bool
    {
        if ($user instanceof Admin) {
            return in_array($user->peran, [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN], true);
        }

        return (int) $tagihan->layananInternet->pelanggan_id === (int) $user->id;
    }

    public function create(Admin $admin): bool
    {
        // Khusus Admin Keuangan / Super Admin: generate tagihan manual per
        // pelanggan (kasus testing / tagihan yang gagal dibuat otomatis).
        return in_array($admin->peran, [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN], true);
    }

    public function update(Admin $admin, Tagihan $tagihan): bool
    {
        // Data tagihan tidak boleh diubah manual sama sekali — jaga integritas snapshot billing.
        // Sesuai requirement: Keuangan tidak boleh mengubah data.
        return false;
    }

    /**
     * Generate ulang / ubah jumlah bulan tagihan. Khusus Keuangan / Super Admin,
     * dan hanya untuk tagihan yang BELUM dibayar (yang sudah lunas tak bisa diubah).
     */
    public function regenerate(Admin $admin, Tagihan $tagihan): bool
    {
        return in_array($admin->peran, [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN], true)
            && $tagihan->status_pembayaran !== StatusPembayaranEnum::SUDAH_BAYAR;
    }
}