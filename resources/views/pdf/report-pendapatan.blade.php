<!DOCTYPE html>
<html>
<head>
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
</body>
</html>
