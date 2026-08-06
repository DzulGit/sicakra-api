<?php

namespace App\Models;

use App\Enums\StatusPembayaranEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tagihan extends Model
{
    use HasFactory;

    protected $table = 'tagihan';

    protected $fillable = [
        'nomor_tagihan',
        'layanan_internet_id',
        'periode_bulan',
        'periode_tahun',
        'nama_paket_snapshot',
        'kecepatan_snapshot_mbps',
        'harga_snapshot',
        'total_tagihan',
        'jumlah_bulan',
        'tanggal_jatuh_tempo',
        'status_pembayaran',
        'xendit_invoice_id',
        'xendit_external_id',
        'xendit_invoice_url',
        'xendit_invoice_status',
        'xendit_invoice_expires_at',
        'xendit_invoice_retry_count',
        'retry_count',
        'dibayar_pada',
    ];

    protected $casts = [
        'status_pembayaran' => StatusPembayaranEnum::class,
        'tanggal_jatuh_tempo' => 'date',
        'dibayar_pada' => 'datetime',
        'xendit_invoice_expires_at' => 'datetime',
        'harga_snapshot' => 'decimal:2',
        'total_tagihan' => 'decimal:2',
        'retry_count' => 'integer',
    ];

    protected $appends = [
        'periode_akhir_bulan',
        'periode_akhir_tahun',
    ];

    public function getPeriodeAkhirBulanAttribute(): int
    {
        return $this->periodeBulanAkhir()['bulan'];
    }

    public function getPeriodeAkhirTahunAttribute(): int
    {
        return $this->periodeBulanAkhir()['tahun'];
    }

    /**
     * Periode akhir = periode awal + (jumlah_bulan - 1). Dipakai frontend
     * untuk menampilkan rentang tagihan multi-bulan (mis. 2/2026 - 3/2026).
     */
    public function periodeBulanAkhir(): array
    {
        $total = ($this->periode_tahun * 12) + ($this->periode_bulan - 1) + max(1, $this->jumlah_bulan) - 1;

        return [
            'bulan' => (($total % 12) + 11) % 12 + 1,
            'tahun' => intdiv($total - 1, 12),
        ];
    }

    public function layananInternet(): BelongsTo
    {
        return $this->belongsTo(LayananInternet::class, 'layanan_internet_id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'tagihan_id');
    }
}
