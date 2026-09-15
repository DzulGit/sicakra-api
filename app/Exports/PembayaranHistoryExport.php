<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Laporan riwayat pembayaran.
 *
 * Satu BARIS = SATU alokasi (pembayaran_tagihan), BUKAN satu pembayaran.
 * Pembayaran yang menjangkau banyak tagihan muncul berulang dengan nominal
 * alokasi yang benar (PembayaranTagihan.jumlah_dialokasikan) agar bisa
 * di-SUM/di-pivot langsung di Excel.
 */
class PembayaranHistoryExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    private array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function collection()
    {
        return collect($this->rows);
    }

    public function headings(): array
    {
        return [
            'Tanggal Pembayaran',
            'Waktu Pembayaran',
            'No. Pembayaran',
            'No. Tagihan',
            'Pelanggan',
            'Periode Tagihan',
            'Total Tagihan',
            'Total Pembayaran',
            'Nominal Dialokasikan',
            'Total Terbayar Tagihan',
            'Sisa Tagihan',
            'Status Tagihan',
            'Tanggal Lunas',
            'Metode Pembayaran',
            'Provider',
            'Status Transaksi',
            'Provider Reference',
            'Provider External ID',
            'Dibayar Oleh',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ]],
        ];
    }
}