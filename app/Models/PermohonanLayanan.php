<?php

namespace App\Models;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PermohonanLayanan extends Model
{
    use HasFactory;

    protected $table = 'permohonan_layanan';

    protected $fillable = [
        'nomor_permohonan',
        'pelanggan_id',
        'jenis_permohonan',
        'layanan_internet_id',
        'paket_internet_id',
        'tipe_paket',
        'nama_paket_custom',
        'kecepatan_custom_mbps',
        'harga_custom',
        'catatan_custom',
        'alasan',
        'alamat_pemasangan',
        'detail_alamat',
        'paket_internet_id_baru',
        'latitude',
        'longitude',
        'status',
        'alasan_ditolak',
        'alasan_ditunda',
        'diproses_oleh',
    ];

    protected $casts = [
        'jenis_permohonan' => JenisPermohonanEnum::class,
        'tipe_paket' => TipePaketEnum::class,
        'status' => StatusPermohonanEnum::class,
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'harga_custom' => 'decimal:2',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function paketInternet(): BelongsTo
    {
        return $this->belongsTo(PaketInternet::class, 'paket_internet_id');
    }

    public function diprosesOleh(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'diproses_oleh');
    }

    // Hanya terisi jika jenis_permohonan = relokasi / ganti_paket
    public function layananDirelokasi(): BelongsTo
    {
        return $this->belongsTo(LayananInternet::class, 'layanan_internet_id');
    }

    // Paket baru yang diminta (hanya untuk ganti_paket)
    public function paketInternetBaru(): BelongsTo
    {
        return $this->belongsTo(PaketInternet::class, 'paket_internet_id_baru');
    }

    // Hanya terisi jika jenis_permohonan = pemasangan_baru & sudah DIKONVERSI
    public function layananHasilKonversi(): HasOne
    {
        return $this->hasOne(LayananInternet::class, 'permohonan_layanan_id');
    }

    public function jadwalKerja(): HasMany
    {
        // Sesuaikan JadwalKerja::class dengan nama Model lu yang bener
        return $this->hasMany(JadwalKerja::class, 'permohonan_layanan_id');
    }

    public function riwayatStatus(): HasMany
    {
        return $this->hasMany(RiwayatStatusPermohonan::class, 'permohonan_layanan_id');
    }
}