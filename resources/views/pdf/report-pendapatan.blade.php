<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Laporan Pendapatan {{ $labelPeriode }}</title>
  <style>
    @page { margin: 14mm 11mm 16mm 11mm; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 9px; color: #1a1a1a; }
    .header { margin-bottom: 4mm; padding-bottom: 3mm; border-bottom: 2px solid #0f172a; }
    .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 1mm; }
    .header p { font-size: 9px; color: #64748b; line-height: 1.4; }
    .section { margin-top: 5mm; }
    .section-title { font-size: 11px; font-weight: bold; color: #0f172a; margin-bottom: 2mm; padding-bottom: 1mm; border-bottom: 1px solid #cbd5e1; }
    .empty { color: #94a3b8; padding: 3mm 0; }

    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #e2e8f0; padding: 1.5mm 1.5mm; text-align: left; vertical-align: top; word-wrap: break-word; overflow: hidden; }
    th { background: #f1f5f9; font-weight: bold; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    td.num, td.nom, th.num { text-align: right; }
    .status-lunas { color: #16a34a; font-weight: bold; }
    .status-belum { color: #b45309; }
    .status-blm { color: #94a3b8; }

    .summary { width: 100%; border-collapse: collapse; }
    .summary td { border: 1px solid #e2e8f0; padding: 2mm 2.5mm; }
    .summary .label { width: 42%; background: #f8fafc; color: #334155; }
    .summary .nilai { font-weight: bold; }
    .summary .total-cell { background: #eef2ff; font-weight: bold; }

    .footer-note { margin-top: 5mm; font-size: 7.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
  </style>
</head>
<body>
  <div class="header">
    <h1>LAPORAN PENDAPATAN</h1>
    <p>Periode: {{ $labelPeriode }}</p>
    <p>Dibuat pada: {{ $generatedAt }} WIB</p>
  </div>

  <div class="section">
    <p class="section-title">RINGKASAN</p>
    <table class="summary">
      <tr>
        <td class="label">Total Tagihan Tercatat</td>
        <td class="nilai">Rp {{ number_format($ringkasanTotal['total_tagihan'], 0, ',', '.') }}</td>
        <td class="label">Total Kredit / Deposit Masuk</td>
        <td class="nilai">Rp {{ number_format($ringkasanTotal['kredit_masuk'], 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td class="label">Total Pembayaran Masuk</td>
        <td class="nilai">Rp {{ number_format($ringkasanTotal['pembayaran_masuk'], 0, ',', '.') }}</td>
        <td class="label">Total Kredit / Deposit Digunakan</td>
        <td class="nilai">Rp {{ number_format($ringkasanTotal['kredit_digunakan'], 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td class="label">Total Dialokasikan ke Tagihan</td>
        <td class="nilai">Rp {{ number_format($ringkasanTotal['dialokasikan'], 0, ',', '.') }}</td>
        <td class="label">Tagihan Lunas</td>
        <td class="total-cell">{{ $ringkasanTotal['tagihan_lunas'] }} tagihan</td>
      </tr>
      <tr>
        <td class="label">Tagihan Belum Lunas / Sedang Cicil</td>
        <td class="nilai">{{ $ringkasanTotal['tagihan_belum_lunas'] }} tagihan</td>
        <td class="label"></td>
        <td></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <p class="section-title">DETAIL TAGIHAN</p>
    <table>
      <thead>
        <tr>
          <th style="width:4%">No</th>
          <th style="width:11%">No Tagihan</th>
          <th style="width:19%">Pelanggan</th>
          <th style="width:8%">Periode</th>
          <th style="width:11%">Total Tagihan</th>
          <th style="width:11%">Terbayar</th>
          <th style="width:10%">Kredit Digunakan</th>
          <th style="width:11%">Sisa</th>
          <th style="width:9%">Status</th>
          <th style="width:6%">Tgl Lunas</th>
        </tr>
      </thead>
      <tbody>
        @forelse($ringkasan as $i => $r)
          @php
            $status = match($r['status']) {
              'Lunas' => 'lunas',
              'Belum Bayar' => 'belum',
              default => 'blm',
            };
          @endphp
          <tr>
            <td class="nom">{{ $i + 1 }}</td>
            <td>{{ $r['nomor_tagihan'] }}</td>
            <td>{{ $r['pelanggan'] }}</td>
            <td>{{ $r['periode'] }}</td>
            <td class="num">Rp {{ number_format($r['total_tagihan'], 0, ',', '.') }}</td>
            <td class="num">Rp {{ number_format($r['total_terbayar'], 0, ',', '.') }}</td>
            <td class="num">@if((float) $r['dibayar_kredit'] > 0)Rp {{ number_format($r['dibayar_kredit'], 0, ',', '.') }}@else—@endif</td>
            <td class="num">Rp {{ number_format($r['sisa'], 0, ',', '.') }}</td>
            <td class="status-{{ $status }}">{{ $r['status'] }}</td>
            <td>{{ $r['tanggal_lunas'] ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="10" class="empty">Tidak ada data tagihan untuk periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="section">
    <p class="section-title">DETAIL PEMBAYARAN</p>
    <table>
      <thead>
        <tr>
          <th style="width:4%">No</th>
          <th style="width:11%">No Pembayaran</th>
          <th style="width:13%">Tanggal / Waktu</th>
          <th style="width:15%">Pelanggan</th>
          <th style="width:12%">Total Dibayar</th>
          <th style="width:9%">Metode</th>
          <th style="width:9%">Provider</th>
          <th style="width:9%">Status</th>
          <th style="width:18%">Referensi</th>
        </tr>
      </thead>
      <tbody>
        @forelse($transaksi as $i => $t)
          <tr>
            <td class="nom">{{ $i + 1 }}</td>
            <td>{{ $t['nomor_pembayaran'] }}</td>
            <td>{{ $t['waktu'] }}</td>
            <td>{{ $t['pelanggan'] }}</td>
            <td class="num">Rp {{ number_format($t['jumlah_dibayar'], 0, ',', '.') }}</td>
            <td>{{ $t['metode'] ?: '—' }}</td>
            <td>{{ $t['provider'] ?: '—' }}</td>
            <td>{{ $t['status'] }}</td>
            <td>{{ $t['referensi'] ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="9" class="empty">Tidak ada data pembayaran untuk periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="section">
    <p class="section-title">DETAIL ALOKASI TAGIHAN</p>
    <table>
      <thead>
        <tr>
          <th style="width:4%">No</th>
          <th style="width:13%">Tanggal / Waktu</th>
          <th style="width:13%">No Pembayaran</th>
          <th style="width:14%">No Tagihan</th>
          <th style="width:13%">Jumlah Dialokasikan</th>
          <th style="width:10%">Sumber</th>
          <th style="width:12%">Sisa Tagihan</th>
        </tr>
      </thead>
      <tbody>
        @forelse($alokasi as $i => $a)
          <tr>
            <td class="nom">{{ $i + 1 }}</td>
            <td>{{ $a['waktu'] }}</td>
            <td>{{ $a['nomor_pembayaran'] }}</td>
            <td>{{ $a['nomor_tagihan'] }}</td>
            <td class="num">Rp {{ number_format($a['jumlah_dialokasikan'], 0, ',', '.') }}</td>
            <td>{{ $a['sumber'] }}</td>
            <td class="num">Rp {{ number_format($a['sisa_tagihan'], 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="empty">Tidak ada data alokasi untuk periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="section">
    <p class="section-title">SALDO KREDIT / DEPOSIT</p>
    <table>
      <thead>
        <tr>
          <th style="width:4%">No</th>
          <th style="width:13%">Tanggal / Waktu</th>
          <th style="width:15%">Pelanggan</th>
          <th style="width:9%">Jenis</th>
          <th style="width:12%">Nominal</th>
          <th style="width:12%">Saldo Berjalan</th>
          <th style="width:35%">Keterangan</th>
        </tr>
      </thead>
      <tbody>
        @forelse($saldoKredit as $i => $m)
          <tr>
            <td class="nom">{{ $i + 1 }}</td>
            <td>{{ $m['waktu'] }}</td>
            <td>{{ $m['pelanggan'] }}</td>
            <td>{{ $m['jenis'] }}</td>
            <td class="num">Rp {{ number_format($m['jumlah'], 0, ',', '.') }}</td>
            <td class="num">Rp {{ number_format($m['sisa_saldo'], 0, ',', '.') }}</td>
            <td>{{ $m['keterangan'] ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="empty">Tidak ada mutasi saldo kredit untuk periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <p class="footer-note">
    Pembayaran gabungan dihitung sebagai satu transaksi (jumlah dibayar = total uang masuk).
    Nominal dialokasikan per tagihan ditampilkan di bagian alokasi. Kredit/deposit tidak dihitung ganda sebagai pendapatan baru.
  </p>
</body>
</html>