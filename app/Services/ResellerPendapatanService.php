<?php

namespace App\Services;

use App\Enums\StatusTransaksiEnum;
use App\Models\PembayaranTagihan;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pendapatan reseller berdasarkan alokasi pembayaran BERHASIL
 * ke tagihan milik reseller (join tagihan -> layanan -> pelanggan),
 * bukan total pembayaran customer.
 */
class ResellerPendapatanService
{
    public const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function totalPendapatan(int|array $idList): float
    {
        return (float) $this->queryAlokasiPembayaran($idList)
            ->sum('pembayaran_tagihan.jumlah_dialokasikan');
    }

    /** Total alokasi per reseller, key = id reseller. */
    public function pendapatanPerReseller(int|array|Collection $idList): Collection
    {
        return $this->queryAlokasiPembayaran($idList)
            ->selectRaw('pelanggan.reseller_id, SUM(pembayaran_tagihan.jumlah_dialokasikan) as total')
            ->groupBy('pelanggan.reseller_id')
            ->pluck('total', 'pelanggan.reseller_id')
            ->map(fn ($total) => (float) $total);
    }

    /** Total pendapatan reseller pada periode tertentu. */
    public function totalPendapatanPeriode(int $id, ?int $tahun, ?int $bulan): float
    {
        $query = $this->queryAlokasiPembayaran($id)
            ->when($tahun, fn ($q) => $q->whereYear('pembayaran.dibayar_pada', $tahun));
        if ($bulan !== null) {
            $query->whereMonth('pembayaran.dibayar_pada', $bulan);
        }

        return (float) $query->sum('pembayaran_tagihan.jumlah_dialokasikan');
    }

    /** Trend pendapatan 12 bulan terakhir [{bulan, jumlah}]. */
    public function trendPendapatan(int|array $idList): array
    {
        $mulai = Carbon::now()->startOfMonth()->subMonths(11);

        $rows = $this->queryAlokasiPembayaran($idList)
            ->where('pembayaran.dibayar_pada', '>=', $mulai)
            ->selectRaw('date(pembayaran.dibayar_pada) as tanggal, SUM(pembayaran_tagihan.jumlah_dialokasikan) as total')
            ->groupBy('tanggal')
            ->get();

        $perBulan = [];
        foreach ($rows as $row) {
            $kode = Carbon::parse($row->tanggal)->format('Y-m');
            $perBulan[$kode] = ($perBulan[$kode] ?? 0) + (float) $row->total;
        }

        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $tgl = $mulai->copy()->addMonths($i);
            $kode = $tgl->format('Y-m');

            $trend[] = [
                'bulan' => self::NAMA_BULAN[(int) $tgl->format('n')].' '.substr((string) $tgl->year, 2),
                'jumlah' => (float) ($perBulan[$kode] ?? 0),
            ];
        }

        return $trend;
    }

    private function queryAlokasiPembayaran(int|array|Collection $idList): Builder
    {
        $ids = $idList instanceof Collection ? $idList->all() : (is_array($idList) ? $idList : [$idList]);

        return PembayaranTagihan::query()
            ->join('pembayaran', 'pembayaran_tagihan.pembayaran_id', '=', 'pembayaran.id')
            ->join('tagihan', 'pembayaran_tagihan.tagihan_id', '=', 'tagihan.id')
            ->join('layanan_internet', 'tagihan.layanan_internet_id', '=', 'layanan_internet.id')
            ->join('pelanggan', 'layanan_internet.pelanggan_id', '=', 'pelanggan.id')
            ->where('pembayaran.status', StatusTransaksiEnum::BERHASIL)
            ->whereIn('pelanggan.reseller_id', $ids)
            ->whereNotNull('pembayaran.dibayar_pada');
    }
}