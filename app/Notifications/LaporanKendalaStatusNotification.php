<?php

namespace App\Notifications;

use App\Enums\StatusLaporanEnum;
use App\Models\LaporanKendala;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaporanKendalaStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LaporanKendala $laporan,
        public StatusLaporanEnum $status,
        public ?string $hasil = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjek = $this->status === StatusLaporanEnum::SELESAI
            ? 'Kendala Telah Diselesaikan'
            : 'Laporan Kendala Ditutup';

        return (new MailMessage)
            ->subject("{$subjek} — {$this->laporan->nomor_laporan}")
            ->view('emails.laporan-status', [
                'nama' => $notifiable->nama_lengkap,
                'nomorLaporan' => $this->laporan->nomor_laporan,
                'status' => $this->status->value,
                'hasil' => $this->hasil,
            ]);
    }
}
