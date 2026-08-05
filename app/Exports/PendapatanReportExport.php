<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PendapatanReportExport implements FromCollection, WithHeadings, WithMapping, WithStartRow, WithStyles, WithColumnWidths, WithEvents
{
    public function __construct(
        private readonly Collection $pembayaran,
        private readonly string $namaBulan,
        private readonly int $tahun,
        private readonly float $total,
        private readonly int $jumlah,
    ) {
    }

    public function collection(): Collection
    {
        return $this->pembayaran;
    }

    public function headings(): array
    {
        return [
            'No.',
            'Nomor Tagihan',
            'Nomor Pelanggan',
            'Pelanggan',
            'Nomor Layanan',
            'Paket',
            'Metode Pembayaran',
            'Tanggal Bayar',
            'Jumlah Dibayar',
        ];
    }

    public function map($item): array
    {
        $tagihan = $item->tagihan;
        $layanan = $tagihan?->layananInternet;
        $pelanggan = $layanan?->pelanggan;

        return [
            null,
            $tagihan?->nomor_tagihan,
            $pelanggan?->nomor_pelanggan,
            $pelanggan?->nama_lengkap,
            $layanan?->nomor_layanan,
            $tagihan?->nama_paket_snapshot,
            ucwords((string) $item->metode_pembayaran),
            $item->dibayar_pada?->format('d/m/Y H:i'),
            (float) $item->jumlah_dibayar,
        ];
    }

    public function startRow(): int
    {
        return 5;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 18, 'C' => 18, 'D' => 26, 'E' => 18,
            'F' => 18, 'G' => 18, 'H' => 20, 'I' => 18,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            5 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']]],
            'I6:I1000' => ['numberFormat' => ['formatCode' => '#,##0']],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:I1');
                $sheet->setCellValue('A1', 'SICAKRA — Laporan Pendapatan Bulanan');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

                $sheet->mergeCells('A2:I2');
                $sheet->setCellValue('A2', "Periode: {$this->namaBulan} {$this->tahun}");
                $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('666666'));

                $sheet->mergeCells('A3:I3');
                $sheet->setCellValue('A3', "Jumlah Pembayaran: {$this->jumlah} transaksi   |   Total Pendapatan: Rp ".number_format($this->total, 0, ',', '.'));
                $sheet->getStyle('A3')->getFont()->setBold(true);

                $sheet->freezePane('A6');
                $sheet->getStyle('A5:I5')->getAlignment()->setHorizontal('center');

                $last = $sheet->getHighestRow();
                if ($last >= 6) {
                    $sheet->getStyle("A5:I{$last}")->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->getColor()->setARGB('999999');

                    for ($row = 6; $row <= $last; $row++) {
                        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setVertical('center');
                        $sheet->getCell("A{$row}")->setValue($row - 5);
                    }
                }

                if ($last === 5) {
                    $sheet->mergeCells('A6:I6');
                    $sheet->setCellValue('A6', 'Tidak ada pembayaran pada periode ini.');
                    $sheet->getStyle('A6')->getAlignment()->setHorizontal('center');
                }
            },
        ];
    }
}