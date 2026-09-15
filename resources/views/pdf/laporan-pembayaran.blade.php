<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Laporan Riwayat Pembayaran</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #1a1a1a; padding: 20px; }
    .header { margin-bottom: 16px; }
    .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
    .header p { font-size: 10px; color: #666; }
    .tagihan { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; page-break-inside: auto; }
    .tagihan-title { font-size: 12px; font-weight: bold; margin-bottom: 1px; }
    .tagihan-periode { font-size: 10px; color: #666; margin-bottom: 6px; }
    .ringkasan { display: table; width: 100%; margin-bottom: 8px; }
    .ringkasan .baris { display: table-row; }
    .ringkasan .label { display: table-cell; width: 150px; font-size: 10px; color: #64748b; padding: 2px 0; }
    .ringkasan .nilai { display: table-cell; font-size: 10px; font-weight: bold; padding: 2px 0; }
    .status-lunas { color: #16a34a; }
    .status-cicil { color: #2563eb; }
    ul.riwayat { list-style: none; margin-top: 4px; }
    ul.riwayat li { margin-bottom: 6px; padding-left: 10px; border-left: 2px solid #e2e8f0; }
    ul.riwayat .waktu { font-size: 10px; font-weight: bold; }
    ul.riwayat .detail { font-size: 9.5px; color: #334155; }
    ul.riwayat .keterangan { font-size: 9px; color: #94a3b8; }
    .footer-note { margin-top: 14px; font-size: 9px; color: #94a3b8; }
  </style>
</head>
<body>
  <div class="header">
    <h1>Laporan Riwayat Pembayaran</h1>
    <p>Periode: {{ $labelPeriode }}</p>
  </div>

  @forelse($perTagihan as $g)
    @php
      $d = $g['detail'];
      $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
      $status = $d['status_pembayaran'] === 'belum_diterbitkan'
        ? ['label' => 'Belum Diterbitkan', 'class' => '']
        : match ($d['status_tampilan']) {
            'lunas' => ['label' => 'Lunas', 'class' => 'status-lunas'],
            'sedang_dicicil' => ['label' => 'Sedang Dicicil', 'class' => 'status-cicil'],
            default => ['label' => 'Belum Bayar', 'class' => ''],
          };
    @endphp
    <div class="tagihan">
      <p class="tagihan-title">{{ $g['nomor_tagihan'] }}</p>
      <p class="tagihan-periode">Periode: {{ $g['periode'] }}</p>

      <div class="ringkasan">
        <div class="baris"><span class="label">Total Tagihan</span><span class="nilai">{{ $rp($d['total_tagihan']) }}</span></div>
        <div class="baris"><span class="label">Terbayar</span><span class="nilai">{{ $rp($d['sudah_dibayar']) }}</span></div>
        @if((float) $d['saldo_kredit_digunakan'] > 0)
          <div class="baris"><span class="label">Saldo Kredit Digunakan</span><span class="nilai">{{ $rp($d['saldo_kredit_digunakan']) }}</span></div>
        @endif
        <div class="baris"><span class="label">Sisa</span><span class="nilai">{{ $rp($d['sisa_tagihan']) }}</span></div>
        <div class="baris"><span class="label">Status</span><span class="nilai {{ $status['class'] }}">{{ $status['label'] }}</span></div>
        @if(!$d['tanggal_lunas'])
          <div class="baris"><span class="label">Lunas Pada</span><span class="nilai">—</span></div>
        @else
          <div class="baris"><span class="label">Lunas Pada</span><span class="nilai">{{ $d['tanggal_lunas'] }} WIB</span></div>
        @endif
      </div>

      <ul class="riwayat">
        @forelse($g['masuk'] as $m)
          <li>
            <p class="waktu">{{ $m['waktu_wib'] }} WIB</p>
            <p class="detail">{{ $m['nomor_pembayaran'] }} — {{ $rp($m['jumlah']) }}</p>
            <p class="keterangan">{{ $m['metode_pembayaran'] ?? '—' }} · {{ $m['provider'] ?? '—' }} · {{ $m['status'] }}</p>
          </li>
        @empty
          <li><p class="keterangan">Tidak ada alokasi pembayaran.</p></li>
        @endforelse
      </ul>
    </div>
  @empty
    <p style="color:#94a3b8;">Tidak ada data untuk periode ini.</p>
  @endforelse

  <p class="footer-note">Nominal sisa dihitung dari transaksi Berhasil + pemakaian saldo kredit. Waktu dalam WIB.</p>
</body>
</html>