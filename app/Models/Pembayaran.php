<?php

namespace App\Models;

use App\Enums\StatusTransaksiEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    protected $fillable = [
        'tagihan_id',
        'metode_pembayaran',
        'dibayar_oleh',
        'jumlah_dibayar',
        'referensi_xendit',
        'status',
        'payload_webhook',
        'dibayar_pada',
    ];

    protected $casts = [
        'status' => StatusTransaksiEnum::class,
        'payload_webhook' => 'array',
        'jumlah_dibayar' => 'decimal:2',
        'dibayar_pada' => 'datetime',
    ];

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class, 'tagihan_id');
    }
}
