<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResellerOtpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otpCode,
        public string $namaLengkap,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode Verifikasi Ganti Email Reseller',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reseller-otp',
            with: [
                'otpCode' => $this->otpCode,
                'namaLengkap' => $this->namaLengkap,
            ],
        );
    }
}