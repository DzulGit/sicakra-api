<?php

namespace App\Notifications;

use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Notifications\Notification;

class PembayaranTagihanNotification extends Notification
{
    public function __construct(public Tagihan $tagihan, public Pembayaran $pembayaran) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $nama = $this->tagihan->layananInternet?->pelanggan?->nama_lengkap ?? '-';

        return [
            'title' => 'Pembayaran Tagihan',
            'message' => "Pembayaran tagihan {$this->tagihan->nomor_tagihan} sebesar Rp"
                .number_format($this->pembayaran->jumlah_dibayar, 0, ',', '.')
                ." dari {$nama} berhasil diterima.",
            'type' => 'pembayaran',
            'action_url' => "/admin/keuangan/tagihan/{$this->tagihan->id}",
        ];
    }
}
