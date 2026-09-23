<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShadowSesi extends Model
{
    protected $table = 'shadow_sesi';

    protected $fillable = [
        'admin_id',
        'reseller_id',
        'kode_hash',
        'kode_kedaluwarsa_pada',
        'token_id',
        'token_kedaluwarsa_pada',
        'diakhiri_pada',
        'diakhiri_oleh',
    ];

    protected $casts = [
        'kode_kedaluwarsa_pada' => 'datetime',
        'token_kedaluwarsa_pada' => 'datetime',
        'diakhiri_pada' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reseller_id');
    }

    public function sedangAktif(): bool
    {
        return $this->token_kedaluwarsa_pada?->isFuture() && ! $this->diakhiri_pada;
    }
}