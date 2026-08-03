<?php

namespace App\Models;

use App\Enums\StatusPembayaranEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tagihan extends Model
{
    use HasFactory;

    protected $table = 'tagihan';

    protected $fillable = [
        'nomor_tagihan',
        'layanan_internet_id',
        'periode_bulan',
        'periode_tahun',
        'nama_paket_snapshot',
        'kecepatan_snapshot_mbps',
        'harga_snapshot',
        'total_tagihan',
        'jumlah_bulan',
        'tanggal_jatuh_tempo',
        'status_pembayaran',
        'xendit_invoice_id',
        'xendit_external_id',
        'xendit_invoice_url',
        'xendit_invoice_status',
        'xendit_invoice_expires_at',
        'xendit_invoice_retry_count',
        'dibayar_pada',
    ];

    protected $casts = [
        'status_pembayaran' => StatusPembayaranEnum::class,
        'tanggal_jatuh_tempo' => 'date',
        'dibayar_pada' => 'datetime',
        'xendit_invoice_expires_at' => 'datetime',
        'harga_snapshot' => 'decimal:2',
        'total_tagihan' => 'decimal:2',
    ];

    public function layananInternet(): BelongsTo
    {
        return $this->belongsTo(LayananInternet::class, 'layanan_internet_id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'tagihan_id');
    }
}