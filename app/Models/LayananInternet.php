<?php

namespace App\Models;

use App\Enums\StatusLayananEnum;
use App\Enums\TipePaketEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LayananInternet extends Model
{
    use HasFactory;

    protected $table = 'layanan_internet';

    protected $fillable = [
        'nomor_layanan',
        'permohonan_layanan_id',
        'pelanggan_id',
        'paket_internet_id',
        'tipe_paket',
        'nama_paket_custom',
        'kecepatan_custom_mbps',
        'harga_custom',
        'alamat_pemasangan',
        'detail_alamat',
        'provinsi',
        'kota',
        'latitude',
        'longitude',
        'status',
        'tanggal_aktif',
        'bebas_tagihan_bulan',
        'tanggal_mulai_penagihan',
    ];

    protected $casts = [
        'tipe_paket' => TipePaketEnum::class,
        'status' => StatusLayananEnum::class,
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'harga_custom' => 'decimal:2',
        'tanggal_aktif' => 'date',
        'bebas_tagihan_bulan' => 'integer',
        'tanggal_mulai_penagihan' => 'date',
    ];

    protected $appends = ['masa_aktif_berakhir'];

    public function getMasaAktifBerakhirAttribute(): ?string
    {
        if ($this->status !== StatusLayananEnum::AKTIF) {
            return null;
        }

        // Masa aktif = tanggal mulai penagihan berikutnya (gudang yang sudah dibayar
        // lunas melewati tanggal_mulai_penagihan). Tanpa tanggal awal, fallback ke
        // tanggal_aktif agar UI tetap menampilkan sesuatu.
        if ($this->tanggal_mulai_penagihan) {
            return $this->tanggal_mulai_penagihan->copy()->subDay()->toDateString();
        }

        return $this->tanggal_aktif?->toDateString();
    }

    public function permohonanAsal(): BelongsTo
    {
        return $this->belongsTo(PermohonanLayanan::class, 'permohonan_layanan_id');
    }

    // Permohonan-permohonan relokasi yang pernah/sedang menunjuk layanan ini
    public function permohonanRelokasi(): HasMany
    {
        return $this->hasMany(PermohonanLayanan::class, 'layanan_internet_id');
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function paketInternet(): BelongsTo
    {
        return $this->belongsTo(PaketInternet::class, 'paket_internet_id');
    }

    public function perangkat(): HasMany
    {
        return $this->hasMany(Perangkat::class, 'layanan_internet_id');
    }

    public function riwayatPerubahanPaket(): HasMany
    {
        return $this->hasMany(RiwayatPerubahanPaket::class, 'layanan_internet_id');
    }

    public function riwayatRelokasi(): HasMany
    {
        return $this->hasMany(RiwayatRelokasi::class, 'layanan_internet_id');
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class, 'layanan_internet_id');
    }

    public function laporanKendala(): HasMany
    {
        return $this->hasMany(LaporanKendala::class, 'layanan_internet_id');
    }
}
