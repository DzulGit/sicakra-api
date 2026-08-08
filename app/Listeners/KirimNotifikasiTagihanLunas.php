<?php

namespace App\Listeners;

use App\Events\PembayaranBerhasil;
use App\Notifications\TagihanLunasNotification;

class KirimNotifikasiTagihanLunas
{
    public function handle(PembayaranBerhasil $event): void
    {
        $pelanggan = $event->tagihan->layananInternet?->pelanggan;

        if ($pelanggan?->email) {
            $pelanggan->notify(new TagihanLunasNotification($event->tagihan));
        }
    }
}