<?php

namespace App\Notifications;

use App\Models\PermohonanLayanan;
use Illuminate\Notifications\Notification;

class PendaftarBaruNotification extends Notification
{
    public function __construct(public PermohonanLayanan $permohonan) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $nama = $this->permohonan->pelanggan?->nama_lengkap ?? '-';

        return [
            'title' => 'Pendaftar Baru',
            'message' => "Permohonan {$this->permohonan->nomor_permohonan} dari {$nama} menunggu verifikasi.",
            'type' => 'pendaftaran',
            'action_url' => "/admin/operasional/permohonan-layanan/{$this->permohonan->id}",
        ];
    }
}
