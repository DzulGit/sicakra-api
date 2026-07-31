<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatRelokasi extends Model
{
    use HasFactory;

    protected $table = 'riwayat_relokasi';

    protected $fillable = [
        'layanan_internet_id',
        'permohonan_layanan_id',
        'alamat_lama',
        'latitude_lama',
        'longitude_lama',
        'alamat_baru',
        'latitude_baru',
        'longitude_baru',
        'tanggal_relokasi',
    ];

    protected $casts = [
        'tanggal_relokasi' => 'date',
        'latitude_lama' => 'decimal:7',
        'longitude_lama' => 'decimal:7',
        'latitude_baru' => 'decimal:7',
        'longitude_baru' => 'decimal:7',
    ];

    public function layananInternet(): BelongsTo
    {
        return $this->belongsTo(LayananInternet::class, 'layanan_internet_id');
    }

    public function permohonanLayanan(): BelongsTo
    {
        return $this->belongsTo(PermohonanLayanan::class, 'permohonan_layanan_id');
    }
}