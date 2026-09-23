<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShadowAktivitas extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shadow_sesi_id',
        'admin_id',
        'reseller_id',
        'method',
        'path',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(ShadowSesi::class, 'shadow_sesi_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}