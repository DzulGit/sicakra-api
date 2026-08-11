<?php

namespace App\Notifications;

use App\Models\LaporanKendala;
use Illuminate\Notifications\Notification;

class LaporanKendalaBaruNotification extends Notification
{
    public function __construct(public LaporanKendala $laporan) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $nama = $this->laporan->layananInternet?->pelanggan?->nama_lengkap ?? '-';

        return [
            'title' => 'Laporan Kendala Baru',
            'message' => "Laporan {$this->laporan->nomor_laporan} dari {$nama} menunggu penanganan.",
            'type' => 'laporan_kendala',
            'action_url' => "/admin/operasional/laporan-kendala/{$this->laporan->id}",
        ];
    }
}
