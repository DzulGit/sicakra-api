<?php

namespace App\Listeners;

use App\Events\TagihanDibuat;
use App\Notifications\TagihanDibuatNotification;

class KirimNotifikasiTagihanDibuat
{
    public function handle(TagihanDibuat $event): void
    {
        $pelanggan = $event->tagihan->layananInternet?->pelanggan;

        if ($pelanggan?->email) {
            $pelanggan->notify(new TagihanDibuatNotification($event->tagihan));
        }
    }
}