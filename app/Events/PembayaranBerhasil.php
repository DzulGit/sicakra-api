<?php

namespace App\Events;

use App\Models\Pembayaran;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class PembayaranBerhasil
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public Pembayaran $pembayaran
    ) {}
}