<?php

namespace App\Notifications;

use App\Models\Admin;
use App\Models\Pelanggan;
use Illuminate\Notifications\Notification;

class PelangganBaruDariResellerNotification extends Notification
{
    public function __construct(
        public Pelanggan $pelanggan,
        public Admin $reseller,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Pelanggan Baru dari Reseller',
            'message' => "{$this->reseller->nama_lengkap} mendaftarkan pelanggan {$this->pelanggan->nama_lengkap} dan menunggu verifikasi.",
            'type' => 'pelanggan',
            'action_url' => '/admin/operasional/pelanggan',
        ];
    }
}
