<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class Pelanggan extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'pelanggan';

    protected $fillable = [
        'nomor_pelanggan',
        'nama_lengkap',
        'nik',
        'nomor_hp',
        'email',
        'password',
        'password_sudah_dibuat',
        'tanggal_tagihan',
        'foto_ktp',
        'foto_profil',
        'reseller_id',
    ];

    protected $casts = [
        'password_sudah_dibuat' => 'boolean',
        'tanggal_tagihan' => 'integer',
        'password' => 'hashed',
        'nik' => 'encrypted',
    ];

    protected $hidden = [
        'password',
        'nik_hash',
    ];

    protected $appends = [
        // Catatan: `foto_ktp_url` DIHAPUS. Foto KTP tidak lagi punya URL public;
        // preview hanya lewat endpoint ber-authorize (Operasional/Reseller) yang
        // membaca path dari record pelanggan ini.
    ];

    protected static function booted(): void
    {
        static::saving(function (Pelanggan $pelanggan): void {
            if ($pelanggan->isDirty('nik')) {
                $pelanggan->nik_hash = $pelanggan->nik
                    ? hash('sha256', $pelanggan->nik)
                    : null;
            }
        });
    }

    // Catatan: password default (= nomor_pelanggan) TIDAK di-set di sini,
    // karena saat Pelanggan::create() dipanggil dari PendaftaranService,
    // nomor_pelanggan belum ada (masih null). nomor_pelanggan baru
    // digenerate belakangan di AktivasiAkunPelangganService, saat teknisi
    // menyelesaikan pemasangan — password default juga di-set di titik
    // yang sama itu, supaya keduanya selalu konsisten.

    public function permohonanLayanan(): HasMany
    {
        return $this->hasMany(PermohonanLayanan::class, 'pelanggan_id');
    }

    public function layananInternet(): HasMany
    {
        return $this->hasMany(LayananInternet::class, 'pelanggan_id');
    }

    public function mutasiSaldoKredit(): HasMany
    {
        return $this->hasMany(
            MutasiSaldoKredit::class,
            'pelanggan_id'
        );
    }

    public function reseller()
    {
        return $this->belongsTo(Admin::class, 'reseller_id');
    }

    public function getFotoProfilUrlAttribute(): ?string
    {
        return $this->foto_profil ? Storage::url($this->foto_profil) : null;
    }
}
