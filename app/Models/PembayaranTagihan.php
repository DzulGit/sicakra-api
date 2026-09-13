<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PembayaranTagihan extends Model
{
    use HasFactory;

    protected $table = 'pembayaran_tagihan';

    protected $fillable = [
        'pembayaran_id',
        'tagihan_id',
        'jumlah_dialokasikan',
    ];

    protected $casts = [
        'jumlah_dialokasikan' => 'decimal:2',
    ];

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class, 'pembayaran_id');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class, 'tagihan_id');
    }
}