<!DOCTYPE html>
<html>
<head>
<<<<<<< HEAD
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
=======
  <meta charset="utf-8">
  <title>Laporan Pendapatan {{ $labelPeriode }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #1a1a1a; padding: 20px; }
    .header { margin-bottom: 16px; }
    .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
    .header p { font-size: 10px; color: #666; }
    .total-bar { background: #f8fafc; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; font-size: 11px; }
    .total-bar strong { color: #0f172a; }
    table { width: 100%; border-collapse: collapse; font-size: 9px; }
    th, td { border: 1px solid #e2e8f0; padding: 5px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-weight: bold; font-size: 9px; white-space: nowrap; }
    td.no, td.nomor { white-space: nowrap; }
    .status-lunas { color: #16a34a; }
    .status-nunggak { color: #dc2626; }
    .status-blm { color: #94a3b8; }
    .total-row td { background: #f8fafc; font-weight: bold; border-top: 2px solid #94a3b8; }
  </style>
</head>
<body>
  <div class="header">
    <h1>Laporan Pendapatan — Matriks</h1>
    <p>Periode: {{ $labelPeriode }}</p>
  </div>

  <div class="total-bar">
    <strong>Total Pendapatan: Rp {{ number_format($total, 0, ',', '.') }}</strong>
  </div>

  <table>
    <thead>
      <tr>
        <th>No</th>
        <th>Nama Pelanggan</th>
        <th>Nomor Pelanggan</th>
        @foreach($kolomBulan as $b)
          @php $namaBulan = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'][$b] ?? "Bulan {$b}"; @endphp
          <th>{{ $namaBulan }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse($matrix as $i => $row)
        <tr>
          <td class="no">{{ $i + 1 }}</td>
          <td>{{ $row['nama'] }}</td>
          <td class="nomor">{{ $row['nomor'] }}</td>
          @foreach($kolomBulan as $b)
            @php $cell = $row['cells'][$loop->index] ?? null; @endphp
            @if($cell['status'] === 'lunas')
              <td class="status-lunas">
                {{ $cell['nominal'] }}<br>
                @if(!empty($cell['tanggal']))
                  <small>{{ $cell['tanggal'] }}</small>
                @endif
              </td>
            @elseif($cell['status'] === 'belum_bayar')
              <td class="status-nunggak">Belum Bayar / Nunggak</td>
            @else
              <td class="status-blm">Belum Berlangganan</td>
            @endif
          @endforeach
        </tr>
      @empty
        <tr>
          <td colspan="{{ count($kolomBulan) + 3 }}" style="text-align:center; color:#94a3b8;">
            Tidak ada data untuk periode ini.
          </td>
        </tr>
      @endforelse
      <tr class="total-row">
        <td></td>
        <td colspan="2">Total Pendapatan</td>
        <td colspan="{{ count($kolomBulan) }}">
          Rp {{ number_format($total, 0, ',', '.') }}
        </td>
      </tr>
    </tbody>
  </table>
>>>>>>> api-development
</body>
</html>