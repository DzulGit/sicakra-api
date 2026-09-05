<?php

namespace App\Models;

use App\Enums\HasilKerjaEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class JadwalKerja extends Model
{
    use HasFactory;

    protected $table = 'jadwal_kerja';

    protected $fillable = [
        'permohonan_layanan_id',
        'tim_teknisi_id',
        'tanggal_kerja',
        'hasil',
        'catatan_kendala',
        'foto_dokumentasi',
        'latitude_hasil',
        'longitude_hasil',
        'diisi_oleh',
    ];

    protected $casts = [
        'hasil' => HasilKerjaEnum::class,
        'tanggal_kerja' => 'date',
        'foto_dokumentasi' => 'array',
        'latitude_hasil' => 'decimal:7',
        'longitude_hasil' => 'decimal:7',
    ];

    public function permohonanLayanan(): BelongsTo
    {
        return $this->belongsTo(PermohonanLayanan::class, 'permohonan_layanan_id');
    }

    public function timTeknisi(): BelongsTo
    {
        return $this->belongsTo(TimTeknisi::class, 'tim_teknisi_id');
    }

    public function diisiOleh(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'diisi_oleh');
    }

    /** Sumber kebenaran siapa yang benar-benar bisa akses pekerjaan ini. */
    public function teknisi(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'jadwal_kerja_teknisi', 'jadwal_kerja_id', 'admin_id')
            ->withTimestamps();
    }

    public function getFotoDokumentasiUrlAttribute(): array
    {
        return array_map(
            fn (string $path) => Storage::url($path),
            $this->foto_dokumentasi ?? [],
        );
    }
}