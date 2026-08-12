<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pendapatan {{ $namaBulan }} {{ $tahun }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2937; margin: 0; }
        .kepala { border-bottom: 3px solid #2563eb; padding-bottom: 10px; margin-bottom: 14px; }
        .kepala .perusahaan { font-size: 14px; font-weight: bold; color: #2563eb; }
        .kepala .judul { font-size: 18px; font-weight: bold; margin-top: 4px; }
        .kepala .sub { font-size: 10px; color: #6b7280; margin-top: 2px; }

        table.ringkasan { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.ringkasan th, table.ringkasan td { padding: 6px 8px; border: 1px solid #e5e7eb; }
        table.ringkasan th { background: #eff6ff; color: #1e40af; text-align: left; font-size: 9px; }
        table.ringkasan td { text-align: right; font-weight: bold; font-size: 11px; }
        table.ringkasan td.kiri { text-align: left; font-weight: normal; font-size: 9px; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 4px 4px; text-align: left; }
        table.data th { background: #dbeafe; font-size: 8px; text-align: center; }
        table.data td { font-size: 8px; }
        table.data td.nomor { text-align: center; }
        table.data td.uang { text-align: right; white-space: nowrap; }

        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e5e7eb; }
        .footer .kiri { float: left; color: #6b7280; font-size: 8px; }
        .footer .kanan { float: right; text-align: right; font-size: 11px; }
        .footer .kanan p { margin: 0; }
        .footer .ttd { margin-top: 40px; }
        .clearfix { clear: both; }
    </style>
</head>
<body>
    <div class="kepala">
        <div class="perusahaan">SICAKRA — Sistem Informasi Internet</div>
        <div class="judul">Laporan Pendapatan Bulanan</div>
        <div class="sub">Periode {{ $namaBulan }} {{ $tahun }} · Dicetak {{ $dicetakPada }}</div>
    </div>

    <table class="ringkasan">
        <tr>
            <th style="width:25%">Jumlah Transaksi</th>
            <td class="kiri">{{ number_format($ringkasan['jumlah_transaksi'], 0, ',', '.') }} pembayaran</td>
            <th style="width:25%">Total Pendapatan</th>
            <td>Rp {{ number_format($ringkasan['total_pendapatan'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <th>Rata-rata per Transaksi</th>
            <td class="kiri">Rp {{ number_format($ringkasan['rata_rata'], 0, ',', '.') }}</td>
            <th>Pelanggan Unik</th>
            <td>{{ $ringkasan['pelanggan_unik'] }} pelanggan</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width:3%">No.</th>
                <th style="width:9%">Tanggal Bayar</th>
                <th style="width:9%">No. Tagihan</th>
                <th style="width:9%">Periode</th>
                <th style="width:8%">No. Pelanggan</th>
                <th style="width:11%">Nama Pelanggan</th>
                <th style="width:8%">NIK</th>
                <th style="width:7%">No. HP</th>
                <th style="width:14%">Alamat Pemasangan</th>
                <th style="width:7%">No. Layanan</th>
                <th style="width:11%">Nama Paket</th>
                <th style="width:6%">Kecepatan</th>
                <th style="width:7%">Metode</th>
                <th style="width:7%">Jatuh Tempo</th>
                <th style="width:9%">Total</th>
                <th style="width:9%">Dibayar</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($detail as $item)
                <tr>
                    <td class="nomor">{{ $item['no'] }}</td>
                    <td>{{ $item['tanggal_bayar'] }}</td>
                    <td>{{ $item['nomor_tagihan'] }}</td>
                    <td>{{ $item['periode_tagihan'] }}</td>
                    <td>{{ $item['nomor_pelanggan'] }}</td>
                    <td>{{ $item['nama_pelanggan'] }}</td>
                    <td>{{ $item['nik'] }}</td>
                    <td>{{ $item['nomor_hp'] }}</td>
                    <td>{{ $item['alamat'] }}</td>
                    <td>{{ $item['nomor_layanan'] }}</td>
                    <td>{{ $item['nama_paket'] }}</td>
                    <td>{{ $item['kecepatan'] }}</td>
                    <td>{{ $item['metode'] }}</td>
                    <td>{{ $item['jatuh_tempo'] }}</td>
                    <td class="uang">{{ number_format($item['total_tagihan'], 0, ',', '.') }}</td>
                    <td class="uang">{{ number_format($item['jumlah_dibayar'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="16" style="text-align:center; padding:12px;">Tidak ada pembayaran pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <div class="kiri">
            Dokumen dihasilkan otomatis oleh SICAKRA.<br>
            Periode: {{ $filterLabel }}
        </div>
        <div class="kanan">
            <p>SICAKRA</p>
            <div class="ttd">Petugas Keuangan</div>
        </div>
        <div class="clearfix"></div>
    </div>
</body>
</html>