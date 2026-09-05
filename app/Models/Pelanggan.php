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
        'username',
        'nama_lengkap',
        'nik',
        'nomor_hp',
        'email',
        'password',
        'password_sudah_dibuat',
        'tanggal_tagihan',
        'foto_ktp',
        'foto_selfie_ktp',
        'foto_profil',
    ];

    protected $casts = [
        'password_sudah_dibuat' => 'boolean',
        'tanggal_tagihan' => 'integer',
        'password' => 'hashed',
    ];

    protected $hidden = [
        'password',
    ];

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

    public function getFotoKtpUrlAttribute(): ?string
    {
        return $this->foto_ktp ? Storage::url($this->foto_ktp) : null;
    }

    public function getFotoSelfieKtpUrlAttribute(): ?string
    {
        return $this->foto_selfie_ktp ? Storage::url($this->foto_selfie_ktp) : null;
    }

    public function getFotoProfilUrlAttribute(): ?string
    {
        return $this->foto_profil ? Storage::url($this->foto_profil) : null;
    }
}
