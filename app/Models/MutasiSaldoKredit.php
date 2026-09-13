<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MutasiSaldoKredit extends Model
{
    use HasFactory;

    protected $table = 'mutasi_saldo_kredit';

    protected $fillable = [
        'pelanggan_id',
        'pembayaran_id',
        'tagihan_id',
        'jenis',
        'jumlah',
        'keterangan',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class, 'pembayaran_id');
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class, 'tagihan_id');
    }
}