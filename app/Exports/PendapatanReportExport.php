<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PendapatanReportExport implements FromCollection, WithColumnWidths, WithCustomStartCell, WithEvents, WithHeadings, WithStyles
{
    private const HEADINGS = [
        'No.',
        'Tanggal Bayar',
        'Nomor Tagihan',
        'Periode Tagihan',
        'Nomor Pelanggan',
        'Nama Pelanggan',
        'NIK',
        'No. HP',
        'Alamat Pemasangan',
        'Nomor Layanan',
        'Nama Paket',
        'Kecepatan',
        'Metode',
        'Jatuh Tempo',
        'Total Tagihan',
        'Jumlah Dibayar',
        'Status',
    ];

    private const LAST_COLUMN = 'Q';

    public function __construct(
        private readonly array $detail,
        private readonly string $judul,
        private readonly string $filterLabel,
        private readonly string $periodeLengkap,
        private readonly array $ringkasan,
    ) {}

    public function collection(): Collection
    {
        return collect($this->detail);
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function startCell(): string
    {
        return 'A7';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 17, 'C' => 17, 'D' => 19, 'E' => 17, 'F' => 26,
            'G' => 18, 'H' => 16, 'I' => 38, 'J' => 17, 'K' => 24, 'L' => 11,
            'M' => 15, 'N' => 13, 'O' => 16, 'P' => 16, 'Q' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $barisAkhir = 7 + count($this->detail);

        $gaya = [
            7 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']],
                'alignment' => ['horizontal' => 'center'],
            ],
        ];

        // Format angka hanya sebatas baris data terakhir, supaya lembar kerja
        // tidak penuh baris kosong (dulu dipakai "O8:Q1000" yang membuat
        // scrollbar/file tampak sampai ~baris 1000).
        if ($barisAkhir >= 8) {
            $gaya["O8:P{$barisAkhir}"] = ['numberFormat' => ['formatCode' => '#,##0']];
        }

        return $gaya;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COLUMN;

                $sheet->mergeCells("A1:{$last}1");
                $sheet->setCellValue('A1', 'SICAKRA — '.$this->judul);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

                $sheet->mergeCells("A2:{$last}2");
                $sheet->setCellValue('A2', "Periode: {$this->periodeLengkap}");
                $sheet->getStyle('A2')->getFont()->setColor(new Color('666666'));

                $sheet->mergeCells("A4:{$last}4");
                $sheet->setCellValue('A4', $this->ringkasanLine1());
                $sheet->getStyle('A4')->getFont()->setBold(true);

                $sheet->mergeCells("A5:{$last}5");
                $sheet->setCellValue('A5', $this->ringkasanLine2());
                $sheet->getStyle('A5')->getFont()->setColor(new Color('666666'));

                $sheet->freezePane('A8');
                $sheet->getStyle("A7:{$last}7")->getAlignment()->setHorizontal('center');
                $sheet->getStyle("A7:{$last}7")->getFont()->setSize(10);

                $lastDataRow = 7 + count($this->detail);
                if (count($this->detail) > 0) {
                    $sheet->getStyle("A7:{$last}{$lastDataRow}")->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->getColor()->setARGB('999999');

                    for ($row = 8; $row <= $lastDataRow; $row++) {
                        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setVertical('center');
                        $sheet->getCell("A{$row}")->setValue($row - 7);
                        $sheet->getStyle("I{$row}")->getAlignment()->setWrapText(true);
                    }

                    $sheet->getStyle("O8:P{$lastDataRow}")->getAlignment()->setHorizontal('right');

                    // NIK & No. HP wajib teks — kalau jadi angka bisa kehilangan
                    // digit presisi (NIK 16 digit) atau tampil notasi ilmiah.
                    foreach (['G', 'H'] as $kolom) {
                        $sheet->getStyle("{$kolom}8:{$kolom}{$lastDataRow}")->getNumberFormat()->setFormatCode('@');
                        for ($r = 8; $r <= $lastDataRow; $r++) {
                            $nilai = $sheet->getCell($kolom.$r)->getValue();
                            if ($nilai !== null && $nilai !== '') {
                                $sheet->setCellValueExplicit($kolom.$r, (string) $nilai, DataType::TYPE_STRING);
                            }
                        }
                    }
                } else {
                    $sheet->mergeCells("A8:{$last}8");
                    $sheet->setCellValue('A8', 'Tidak ada pembayaran pada periode ini.');
                    $sheet->getStyle('A8')->getAlignment()->setHorizontal('center');
                }
            },
        ];
    }

    private function ringkasanLine1(): string
    {
        return 'Jumlah Transaksi: '.number_format($this->ringkasan['jumlah_transaksi'], 0, ',', '.')
            .'    |    Total Pendapatan: Rp '.number_format($this->ringkasan['total_pendapatan'], 0, ',', '.');
    }

    private function ringkasanLine2(): string
    {
        return 'Rata-rata per Transaksi: Rp '.number_format($this->ringkasan['rata_rata'], 0, ',', '.')
            .'    |    Pelanggan Unik: '.$this->ringkasan['pelanggan_unik']
            .'    |    Dicetak: '.now()->format('d M Y H:i');
    }
}
