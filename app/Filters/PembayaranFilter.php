<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PembayaranFilter extends QueryFilter
{
    // ?status=pending|berhasil|gagal (status transaksi pembayaran)
    protected function status(Builder $builder, string $nilai): void
    {
        $builder->where('status', $nilai);
    }

    // ?dari=2026-09-01 — batas bawah waktu pembayaran (interpretasi WIB)
    protected function dari(Builder $builder, string $nilai): void
    {
        $builder->whereRaw('COALESCE(dibayar_pada, created_at) >= ?', [
            Carbon::parse($nilai . ' 00:00:00', 'Asia/Jakarta')->utc(),
        ]);
    }

    // ?sampai=2026-09-30
    protected function sampai(Builder $builder, string $nilai): void
    {
        $builder->whereRaw('COALESCE(dibayar_pada, created_at) <= ?', [
            Carbon::parse($nilai . ' 23:59:59', 'Asia/Jakarta')->utc(),
        ]);
    }

    // ?pelanggan=12 (id pelanggan)
    protected function pelanggan(Builder $builder, string $nilai): void
    {
        $builder->where('pelanggan_id', (int) $nilai);
    }

    // ?no_pembayaran=PAY-000123 (atau hanya angkanya)
    protected function noPembayaran(Builder $builder, string $nilai): void
    {
        $angka = preg_replace('/\D/', '', $nilai);

        if ($angka !== null && $angka !== '') {
            $builder->where('id', (int) $angka);
        }
    }

    // ?no_tagihan=INV000052 — filter lewat alokasi pembayaran_tagihan
    protected function noTagihan(Builder $builder, string $nilai): void
    {
        $builder->whereHas(
            'alokasiTagihan.tagihan',
            fn ($q) => $q->where('nomor_tagihan', 'like', "%{$nilai}%")
        );
    }

    // ?metode=tunai
    protected function metode(Builder $builder, string $nilai): void
    {
        $builder->where('metode_pembayaran', $nilai);
    }

    // ?provider=xendit
    protected function provider(Builder $builder, string $nilai): void
    {
        $builder->where('provider', $nilai);
    }

    // ?reseller_id=5 — dipakai admin saat memantau pembayaran reseller tertentu
    protected function resellerId(Builder $builder, string $nilai): void
    {
        $builder->whereHas(
            'pelanggan',
            fn ($q) => $q->where('reseller_id', (int) $nilai)
        );
    }

    /**
     * Asisten kecil agar query param lain yang sama tetap dibaca, mis.
     * Str::camel('status_tagihan') => statusTagihan. Filter status tagihan
     * dilakukan lewat alokasi (pembayaran bisa menjangkau banyak tagihan).
     */
    protected function statusTagihan(Builder $builder, string $nilai): void
    {
        $builder->whereHas(
            'alokasiTagihan.tagihan',
            fn ($q) => $q->where('status_pembayaran', $nilai)
        );
    }
}