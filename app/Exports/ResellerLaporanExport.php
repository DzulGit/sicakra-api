<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ResellerLaporanExport implements WithMultipleSheets
{
    private array $rekap;
    private array $transaksi;
    private string $labelFilter;
    private string $labelPeriode;

    public function __construct(array $rekap, array $transaksi, string $labelFilter, string $labelPeriode)
    {
        $this->rekap = $rekap;
        $this->transaksi = $transaksi;
        $this->labelFilter = $labelFilter;
        $this->labelPeriode = $labelPeriode;
    }

    public function sheets(): array
    {
        return [
            new RekapSheet($this->rekap, $this->labelFilter, $this->labelPeriode),
            new TransaksiSheet($this->transaksi, $this->labelFilter, $this->labelPeriode),
        ];
    }
}

class RekapSheet implements FromArray, WithTitle, WithHeadings, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelFilter;
    private string $labelPeriode;

    public function __construct(array $data, string $labelFilter, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelFilter = $labelFilter;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Ringkasan';
    }

    public function headings(): array
    {
        return [
            ['Laporan Monitoring Reseller'],
            ["Filter: {$this->labelFilter} | Periode: {$this->labelPeriode}"],
            ['No', 'Reseller', 'Email', 'Status', 'Terdaftar', 'Pelanggan', 'Pelanggan Aktif', 'Paket', 'Tagihan Dibuat', 'Lunas', 'Belum Bayar', 'Pendapatan'],
        ];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->data as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['nama'],
                $r['email'],
                $r['status'],
                $r['terdaftar'],
                $r['pelanggan'],
                $r['pelanggan_aktif'],
                $r['paket'],
                $r['tagihan_dibuat'],
                $r['tagihan_lunas'],
                $r['tagihan_belum_bayar'],
                number_format((float) $r['pendapatan'], 0, ',', '.'),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            3 => ['font' => ['bold' => true], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']]],
        ];
    }
}

class TransaksiSheet implements FromArray, WithTitle, WithHeadings, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelFilter;
    private string $labelPeriode;

    public function __construct(array $data, string $labelFilter, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelFilter = $labelFilter;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Transaksi';
    }

    public function headings(): array
    {
        return [
            ['Laporan Monitoring Reseller — Detail Transaksi'],
            ["Filter: {$this->labelFilter} | Periode: {$this->labelPeriode}"],
            ['No', 'Waktu', 'Jenis', 'Nomor', 'Reseller', 'Pelanggan', 'Nominal', 'Status'],
        ];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->data as $i => $t) {
            $rows[] = [
                $i + 1,
                $t['waktu'],
                $t['jenis'] === 'tagihan' ? 'Tagihan Diterbitkan' : 'Pembayaran',
                $t['nomor'],
                $t['reseller'],
                $t['pelanggan'],
                number_format((float) $t['nominal'], 0, ',', '.'),
                $t['status'],
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            3 => ['font' => ['bold' => true], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']]],
        ];
    }
}