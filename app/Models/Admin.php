<?php

namespace App\Models;

use App\Enums\PeranAdminEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'admin';

    protected $fillable = [
        'nama_lengkap',
        'email',
        'password',
        'peran',
        'status_aktif',
        'dibuat_oleh',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'peran' => PeranAdminEnum::class,
        'status_aktif' => 'boolean',
        'password' => 'hashed',
    ];

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'dibuat_oleh');
    }

    public function permohonanDiproses(): HasMany
    {
        return $this->hasMany(PermohonanLayanan::class, 'diproses_oleh');
    }

    public function laporanDitugaskan(): HasMany
    {
        return $this->hasMany(LaporanKendala::class, 'ditugaskan_ke');
    }

    public function laporanDitutup(): HasMany
    {
        return $this->hasMany(LaporanKendala::class, 'ditutup_oleh');
    }

    public function pelanggan(): HasMany
    {
        return $this->hasMany(Pelanggan::class, 'reseller_id');
    }

    /**
     * Cek apakah admin memiliki salah satu dari peran yang diizinkan.
     * Reseller adalah peran TERPISAH (mitra eksternal yang menyewa sistem),
     * tidak setara dengan modul operasional/teknisi/keuangan. Akses reseller
     * dilayani via grup rute khusus 'reseller' di routes/api.php.
     */
    public function memilikiPeran(PeranAdminEnum ...$peranDiizinkan): bool
    {
        return in_array($this->peran, $peranDiizinkan, true);
    }

    public function paketInternet(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaketInternet::class, 'reseller_id');
    }
}
