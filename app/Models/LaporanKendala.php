<?php

namespace App\Models;

use App\Enums\StatusLaporanEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaporanKendala extends Model
{
    use HasFactory;

    protected $table = 'laporan_kendala';

    protected $fillable = [
        'nomor_laporan',
        'layanan_internet_id',
        'kategori_kendala',
        'deskripsi',
        'foto',
        'status',
        'ditugaskan_ke',
        'hasil_penanganan',
        'ditutup_oleh',
    ];

    protected $casts = [
        'status' => StatusLaporanEnum::class,
    ];

    public function layananInternet(): BelongsTo
    {
        return $this->belongsTo(LayananInternet::class, 'layanan_internet_id');
    }

    public function ditugaskanKe(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'ditugaskan_ke');
    }

    public function ditutupOleh(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'ditutup_oleh');
    }
}