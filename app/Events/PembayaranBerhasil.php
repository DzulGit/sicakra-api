<?php

namespace App\Events;

use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class PembayaranBerhasil
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Tagihan $tagihan, public Pembayaran $pembayaran) {}
}
