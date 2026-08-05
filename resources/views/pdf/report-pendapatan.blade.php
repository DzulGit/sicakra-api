<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pendapatan {{ $namaBulan }} {{ $tahun }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 0 0 24px; font-weight: normal; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        .ringkasan { margin-top: 24px; }
        .ringkasan td { border: none; padding: 4px 0; }
        .total { font-weight: bold; font-size: 14px; }
        .footer { margin-top: 32px; color: #6b7280; font-size: 10px; }
    </style>
</head>
<body>
    <h1>SICAKRA — Laporan Pendapatan Bulanan</h1>
    <h2>{{ $namaBulan }} {{ $tahun }}</h2>

    <table class="ringkasan">
        <tr><td>Jumlah Pembayaran Berhasil</td><td>{{ $jumlah }} transaksi</td></tr>
        <tr><td>Total Pendapatan</td><td class="total">{{ number_format((float) $total, 0, ',', '.') }}</td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th>Nomor Tagihan</th>
                <th>Pelanggan</th>
                <th>Metode</th>
                <th>Tanggal</th>
                <th>Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pembayaran as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->tagihan?->nomor_tagihan }}</td>
                    <td>{{ $item->tagihan?->layananInternet?->pelanggan?->nama_lengkap }}</td>
                    <td>{{ $item->metode_pembayaran }}</td>
                    <td>{{ $item->dibayar_pada?->format('d M Y') }}</td>
                    <td>{{ number_format((float) $item->jumlah_dibayar, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Tidak ada pembayaran pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dicetak {{ now()->format('d M Y H:i') }} — SICAKRA Sistem Informasi Internet
    </div>
</body>
</html>
