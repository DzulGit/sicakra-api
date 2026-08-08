<x-emails.layout judul="Kendala Selesai — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Kabar baik — laporan kendala Anda dengan nomor <strong>{{ $nomorLaporan }}</strong> telah diselesaikan.</p>

    <div class="meta">
        <div><strong>Status:</strong> {{ $status }}</div>
    </div>

    @if($hasil)
        <p><strong>Hasil penanganan:</strong> {{ $hasil }}</p>
    @endif

    <p>Jika kendala masih terjadi, jangan ragu untuk membuat laporan baru melalui portal pelanggan.</p>

    <p class="muted">Terima kasih telah mempercayakan layanan internet Anda kepada Sicakra.</p>
</x-emails.layout>