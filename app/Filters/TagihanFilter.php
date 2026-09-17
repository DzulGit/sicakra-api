<?php

namespace App\Filters;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusTransaksiEnum;
use Illuminate\Database\Eloquent\Builder;

class TagihanFilter extends QueryFilter
{
    // ?status=lunas|belum_bayar|tertunggak|sedang_dicicil
    // menghitung status dari data pembayaran secara real-time
    protected function status(Builder $builder, string $nilai): void
    {
        // Ekspresi SQL untuk menghitung total yang sudah terbayar (pembayaran + kredit)
        // sesuai PembayaranAllocationService::hitungTotalPembayaranBerhasil + hitungTotalPemakaianKredit
        $berhasil = StatusTransaksiEnum::BERHASIL->value;
        $pemakaian = 'pemakaian';
        $terbayar = "(SELECT COALESCE(SUM(pt.jumlah_dialokasikan), 0) FROM pembayaran_tagihan pt INNER JOIN pembayaran p ON p.id = pt.pembayaran_id WHERE pt.tagihan_id = tagihan.id AND p.status = '{$berhasil}') + (SELECT COALESCE(SUM(msk.jumlah), 0) FROM mutasi_saldo_kredit msk WHERE msk.tagihan_id = tagihan.id AND msk.jenis = '{$pemakaian}')";

        $tahun = now('Asia/Jakarta')->year;
        $bulan = now('Asia/Jakarta')->month;
        $periodeLampau = "(tagihan.periode_tahun < {$tahun} OR (tagihan.periode_tahun = {$tahun} AND tagihan.periode_bulan < {$bulan}))";
        $layananAktif = "EXISTS (SELECT 1 FROM layanan_internet li WHERE li.id = tagihan.layanan_internet_id AND li.status = '" . StatusLayananEnum::AKTIF->value . "')";

        match ($nilai) {
            'lunas' => $builder->whereRaw("({$terbayar}) >= total_tagihan"),
            'belum_bayar' => $this->bukanTertunggak(
                $builder->whereRaw("({$terbayar}) = 0"),
                $periodeLampau,
                $layananAktif,
            ),
            'tertunggak' => $builder
                ->whereRaw("({$terbayar}) < total_tagihan AND {$periodeLampau}")
                ->whereRaw($layananAktif),
            'sedang_dicicil' => $this->bukanTertunggak(
                $builder->whereRaw("({$terbayar}) > 0 AND ({$terbayar}) < total_tagihan"),
                $periodeLampau,
                $layananAktif,
            ),
            default => null,
        };
    }

    private function bukanTertunggak(Builder $builder, string $periodeLampau, string $layananAktif): Builder
    {
        return $builder->where(function ($q) use ($periodeLampau, $layananAktif) {
            // bukan tertunggak = periode bukan lampau ATAU layanan tidak aktif
            $q->whereRaw("NOT ({$periodeLampau})")
              ->orWhereRaw("NOT ({$layananAktif})");
        });
    }

    // ?periode_bulan=7
    protected function periodeBulan(Builder $builder, string $nilai): void
    {
        $builder->where('periode_bulan', $nilai);
    }

    // ?periode_tahun=2026
    protected function periodeTahun(Builder $builder, string $nilai): void
    {
        $builder->where('periode_tahun', $nilai);
    }

    // ?search=budi → cocok NIK / nama / nomor pelanggan (case-insensitive)
    protected function search(Builder $builder, string $nilai): void
    {
        $nilai = strtolower($nilai);

        $builder->whereHas('layananInternet.pelanggan', function ($q) use ($nilai) {
            $q->where(function ($sq) use ($nilai) {
                $sq->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$nilai}%"])
                    ->orWhereRaw('LOWER(nik) LIKE ?', ["%{$nilai}%"])
                    ->orWhereRaw('LOWER(nomor_pelanggan) LIKE ?', ["%{$nilai}%"]);
            });
        });
    }
}