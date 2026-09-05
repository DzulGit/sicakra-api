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
}
