<?php

namespace App\Listeners;

use App\Enums\PeranAdminEnum;
use App\Events\PembayaranBerhasil;
use App\Models\Admin;
use App\Notifications\PembayaranTagihanNotification;
use Illuminate\Support\Facades\Notification;

class KirimNotifikasiPembayaranAdmin
{
    public function handle(PembayaranBerhasil $event): void
    {
        $pembayaran = $event->pembayaran->load([
            'pelanggan',
            'alokasiTagihan.tagihan.layananInternet.pelanggan',
        ]);

        $tagihan = $pembayaran->alokasiTagihan
            ->first()
            ?->tagihan;

        if (! $tagihan) {
            return;
        }

        Notification::send(
            Admin::where('status_aktif', true)
                ->whereIn('peran', [
                    PeranAdminEnum::KEUANGAN,
                    PeranAdminEnum::SUPER_ADMIN,
                ])
                ->get(),
            new PembayaranTagihanNotification(
                $tagihan,
                $pembayaran
            ),
        );
    }
}