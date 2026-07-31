<?php

namespace App\Events;

use App\Models\Tagihan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class TagihanDibuat
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Tagihan $tagihan) {}
}
