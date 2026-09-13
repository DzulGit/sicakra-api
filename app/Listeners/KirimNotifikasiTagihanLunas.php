<?php

namespace App\Listeners;

use App\Enums\StatusPembayaranEnum;
use App\Events\PembayaranBerhasil;
use App\Notifications\TagihanLunasNotification;

class KirimNotifikasiTagihanLunas
{
    public function handle(PembayaranBerhasil $event): void
    {
        $pembayaran = $event->pembayaran->load([
            'pelanggan',
            'alokasiTagihan.tagihan.layananInternet.pelanggan',
        ]);

        $pelanggan = $pembayaran->pelanggan;

        if (! $pelanggan?->email) {
            return;
        }

        foreach ($pembayaran->alokasiTagihan as $alokasi) {
            $tagihan = $alokasi->tagihan;

            if (! $tagihan) {
                continue;
            }

            if (
                $tagihan->status_pembayaran !==
                StatusPembayaranEnum::SUDAH_BAYAR
            ) {
                continue;
            }

            $pelanggan->notify(
                new TagihanLunasNotification($tagihan)
            );
        }
    }
}