<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PendapatanMatrixExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    private array $data;
    private array $kolomBulan;
    private string $labelPeriode;
    private float $total;

    private const NAMA_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(array $data, array $kolomBulan, string $labelPeriode, float $total)
    {
        $this->data = $data;
        $this->kolomBulan = $kolomBulan;
        $this->labelPeriode = $labelPeriode;
        $this->total = $total;
    }

    public function collection()
    {
        $rows = collect();

        foreach ($this->data as $row) {
            $cells = ['No', 'Nama Pelanggan', 'Nomor Pelanggan'];
            $values = [
                $rows->count() + 1,
                $row['nama'],
                $row['nomor'],
            ];

            foreach ($this->kolomBulan as $bulan) {
                $cells[] = self::NAMA_BULAN[$bulan] ?? "Bulan {$bulan}";
                $cell = $row['cells'][$this->bulanIndex($bulan)] ?? null;

                if ($cell['status'] === 'lunas') {
                    $values[] = "{$cell['nominal']} ({$cell['tanggal']})";
                } elseif ($cell['status'] === 'belum_bayar') {
                    $values[] = 'Belum Bayar / Nunggak';
                } else {
                    $values[] = 'Belum Berlangganan';
                }
            }

            $rows->push($values);
        }

        // Total row
        $totalRow = array_fill(0, 3, '');
        $totalRow[1] = 'Total';
        foreach ($this->kolomBulan as $i => $bulan) {
            $totalRow[] = '';
        }
        $rows->push($totalRow);

        return $rows;
    }

    public function headings(): array
    {
        $headings = ['No', 'Nama Pelanggan', 'Nomor Pelanggan'];
        foreach ($this->kolomBulan as $bulan) {
            $headings[] = self::NAMA_BULAN[$bulan] ?? "Bulan {$bulan}";
        }
        return $headings;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = count($this->kolomBulan) + 3;
        $lastRow = count($this->data) + 3; // +1 header, +1 empty, +1 total

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['bold' => true, 'size' => 10]],
            3 => ['font' => ['bold' => true], 'fill' => [\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'fgColor' => ['rgb' => 'E2E8F0']]],
            $lastRow => ['font' => ['bold' => true]],
        ];
    }

    private function bulanIndex(int $bulan): int
    {
        return array_search($bulan, $this->kolomBulan) ?? 0;
    }
}
