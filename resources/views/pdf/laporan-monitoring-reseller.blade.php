<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Laporan Monitoring Reseller</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #1a1a1a; padding: 20px; }
    .header { margin-bottom: 16px; }
    .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
    .header p { font-size: 10px; color: #666; }
    .meta-bar { background: #f8fafc; padding: 8px 12px; border-radius: 4px; margin-bottom: 14px; font-size: 11px; }
    .meta-bar strong { color: #0f172a; }
    h2 { font-size: 12px; margin: 14px 0 6px; color: #0f172a; }
    table { width: 100%; border-collapse: collapse; font-size: 9px; }
    th, td { border: 1px solid #e2e8f0; padding: 5px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-weight: bold; font-size: 9px; white-space: nowrap; }
    td.no, td.nomor { white-space: nowrap; }
    .total-row td { background: #f8fafc; font-weight: bold; border-top: 2px solid #94a3b8; }
    .badge-tagihan { color: #2563eb; font-weight: bold; }
    .badge-pembayaran { color: #16a34a; font-weight: bold; }
    .keterangan { font-size: 9px; color: #94a3b8; margin-top: 10px; }
  </style>
</head>
<body>
  <div class="header">
    <h1>Laporan Monitoring Reseller</h1>
    <p>SICAKRA — Manajemen Internet WiFi RT/RW</p>
  </div>

  <div class="meta-bar">
    <strong>Filter:</strong> {{ $labelFilter }} &nbsp;·&nbsp; <strong>Periode:</strong> {{ $labelPeriode }} &nbsp;·&nbsp;
    <strong>Dicetak:</strong> {{ now()->format('d M Y H:i') }}
  </div>

  <h2>Ringkasan Per Reseller</h2>
  <table>
    <thead>
      <tr>
        <th>No</th>
        <th>Reseller</th>
        <th>Email</th>
        <th>Status</th>
        <th>Terdaftar</th>
        <th>Pelanggan</th>
        <th>Aktif</th>
        <th>Paket</th>
        <th>Tagihan Dibuat</th>
        <th>Lunas</th>
        <th>Belum Bayar</th>
        <th>Pendapatan</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rekap as $i => $r)
        <tr>
          <td class="no">{{ $i + 1 }}</td>
          <td>{{ $r['nama'] }}</td>
          <td>{{ $r['email'] }}</td>
          <td>{{ $r['status'] }}</td>
          <td class="nomor">{{ $r['terdaftar'] }}</td>
          <td>{{ $r['pelanggan'] }}</td>
          <td>{{ $r['pelanggan_aktif'] }}</td>
          <td>{{ $r['paket'] }}</td>
          <td>{{ $r['tagihan_dibuat'] }}</td>
          <td>{{ $r['tagihan_lunas'] }}</td>
          <td>{{ $r['tagihan_belum_bayar'] }}</td>
          <td>Rp {{ number_format($r['pendapatan'], 0, ',', '.') }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="12" style="text-align:center; color:#94a3b8;">Tidak ada reseller untuk filter ini.</td>
        </tr>
      @endforelse
      <tr class="total-row">
        <td colspan="11" style="text-align:right;">Total Pendapatan</td>
        <td>Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

  <h2>Detail Transaksi</h2>
  <table>
    <thead>
      <tr>
        <th>No</th>
        <th>Waktu</th>
        <th>Jenis</th>
        <th>Nomor</th>
        <th>Reseller</th>
        <th>Pelanggan</th>
        <th>Nominal</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      @forelse($transaksi as $i => $t)
        <tr>
          <td class="no">{{ $i + 1 }}</td>
          <td class="nomor">{{ $t['waktu'] }}</td>
          <td class="{{ $t['jenis'] === 'tagihan' ? 'badge-tagihan' : 'badge-pembayaran' }}">
            {{ $t['jenis'] === 'tagihan' ? 'Tagihan' : 'Pembayaran' }}
          </td>
          <td class="nomor">{{ $t['nomor'] }}</td>
          <td>{{ $t['reseller'] }}</td>
          <td>{{ $t['pelanggan'] }}</td>
          <td>Rp {{ number_format($t['nominal'], 0, ',', '.') }}</td>
          <td>{{ $t['status'] }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="8" style="text-align:center; color:#94a3b8;">Tidak ada transaksi untuk periode ini.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  <p class="keterangan">Dokumen ini dihasilkan otomatis dari sistem SICAKRA.</p>
</body>
</html>