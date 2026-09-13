<?php

namespace App\Models;

use App\Enums\StatusTransaksiEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\PembayaranTagihan;

class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    protected $fillable = [
        'tagihan_id',
        'pelanggan_id',
        'metode_pembayaran',
        'provider',
        'provider_reference',
        'provider_external_id',
        'payment_url',
        'provider_status',
        'provider_expires_at',
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
        'provider_expires_at' => 'datetime',
        'dibayar_pada' => 'datetime',
    ];

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class, 'tagihan_id');
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function alokasiTagihan(): HasMany
    {
        return $this->hasMany(PembayaranTagihan::class, 'pembayaran_id');
    }

    public function mutasiSaldoKredit(): HasMany
    {
        return $this->hasMany(MutasiSaldoKredit::class, 'pembayaran_id');
    }
}