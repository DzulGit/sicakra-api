<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Laporan pendapatan & pembayaran multi-sheet (4 sheet):
 *  1. Ringkasan Tagihan
 *  2. Transaksi Pembayaran
 *  3. Alokasi Tagihan
 *  4. Saldo Kredit
 *
 * Nilai numerik (bukan string) agar dapat di-SUM di Excel.
 */
class LaporanPendapatanMultiSheetExport implements WithMultipleSheets
{
    private array $ringkasan;
    private array $transaksi;
    private array $alokasi;
    private array $saldoKredit;
    private string $labelPeriode;

    public function __construct(array $ringkasan, array $transaksi, array $alokasi, array $saldoKredit, string $labelPeriode)
    {
        $this->ringkasan = $ringkasan;
        $this->transaksi = $transaksi;
        $this->alokasi = $alokasi;
        $this->saldoKredit = $saldoKredit;
        $this->labelPeriode = $labelPeriode;
    }

    public function sheets(): array
    {
        return [
            new RingkasanTagihanSheet($this->ringkasan, $this->labelPeriode),
            new TransaksiPembayaranSheet($this->transaksi, $this->labelPeriode),
            new AlokasiTagihanSheet($this->alokasi, $this->labelPeriode),
            new SaldoKreditSheet($this->saldoKredit, $this->labelPeriode),
        ];
    }
}

class RingkasanTagihanSheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelPeriode;

    public function __construct(array $data, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Ringkasan Tagihan';
    }

    public function array(): array
    {
        $rows = [
            ['Laporan Pendapatan & Pembayaran — Ringkasan Tagihan'],
            ["Periode: {$this->labelPeriode}"],
            ['No', 'No Tagihan', 'No Pelanggan', 'Pelanggan', 'Periode Tagihan', 'Total Tagihan', 'Dibayar (Pembayaran)', 'Dibayar (Kredit)', 'Total Terbayar', 'Sisa', 'Status', 'Tanggal Lunas'],
        ];

        foreach ($this->data as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['nomor_tagihan'],
                $r['nomor_pelanggan'],
                $r['pelanggan'],
                $r['periode'],
                (float) $r['total_tagihan'],
                (float) $r['dibayar_pembayaran'],
                (float) $r['dibayar_kredit'],
                (float) $r['total_terbayar'],
                (float) $r['sisa'],
                $r['status'],
                $r['tanggal_lunas'],
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

class TransaksiPembayaranSheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelPeriode;

    public function __construct(array $data, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Transaksi Pembayaran';
    }

    public function array(): array
    {
        $rows = [
            ['Laporan Pendapatan & Pembayaran — Transaksi Pembayaran'],
            ["Periode: {$this->labelPeriode}"],
            ['No', 'Waktu', 'No Pembayaran', 'Pelanggan', 'Metode', 'Provider', 'Jumlah Dibayar', 'Status', 'Referensi'],
        ];

        foreach ($this->data as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['waktu'],
                $r['nomor_pembayaran'],
                $r['pelanggan'],
                $r['metode'],
                $r['provider'],
                (float) $r['jumlah_dibayar'],
                $r['status'],
                $r['referensi'],
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

class AlokasiTagihanSheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelPeriode;

    public function __construct(array $data, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Alokasi Tagihan';
    }

    public function array(): array
    {
        $rows = [
            ['Laporan Pendapatan & Pembayaran — Alokasi Tagihan'],
            ["Periode: {$this->labelPeriode}"],
            ['No', 'Waktu', 'No Pembayaran', 'No Tagihan', 'Pelanggan', 'Periode Tagihan', 'Jumlah Dialokasikan', 'Sumber', 'Sisa Tagihan'],
        ];

        foreach ($this->data as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['waktu'],
                $r['nomor_pembayaran'],
                $r['nomor_tagihan'],
                $r['pelanggan'],
                $r['periode'],
                (float) $r['jumlah_dialokasikan'],
                $r['sumber'],
                (float) $r['sisa_tagihan'],
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

class SaldoKreditSheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    private array $data;
    private string $labelPeriode;

    public function __construct(array $data, string $labelPeriode)
    {
        $this->data = $data;
        $this->labelPeriode = $labelPeriode;
    }

    public function title(): string
    {
        return 'Saldo Kredit';
    }

    public function array(): array
    {
        $rows = [
            ['Laporan Pendapatan & Pembayaran — Saldo Kredit'],
            ["Periode: {$this->labelPeriode}"],
            ['No', 'Waktu', 'Pelanggan', 'Jenis', 'Jumlah', 'Sisa Saldo', 'Keterangan'],
        ];

        foreach ($this->data as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['waktu'],
                $r['pelanggan'],
                $r['jenis'],
                (float) $r['jumlah'],
                (float) $r['sisa_saldo'],
                $r['keterangan'],
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
