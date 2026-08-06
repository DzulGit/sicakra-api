<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaketInternet extends Model
{
    use HasFactory;

    protected $table = 'paket_internet';

    protected $fillable = [
        'nama_paket',
        'kecepatan_mbps',
        'harga',
        'jumlah_perangkat',
        'deskripsi',
        'status_aktif',
        'promo_gratis_bulan',
    ];

    protected $casts = [
        'status_aktif' => 'boolean',
        'harga' => 'decimal:2',
        'promo_gratis_bulan' => 'integer',
    ];

    public function permohonanLayanan(): HasMany
    {
        return $this->hasMany(PermohonanLayanan::class, 'paket_internet_id');
    }

    public function layananInternet(): HasMany
    {
        return $this->hasMany(LayananInternet::class, 'paket_internet_id');
    }
}
