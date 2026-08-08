<x-emails.layout judul="Laporan Kendala Diterima — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Laporan kendala Anda telah kami terima dan sedang diproses oleh tim kami.</p>

    <div class="meta">
        <div><strong>Nomor Laporan:</strong> {{ $nomorLaporan }}</div>
        <div><strong>Kategori:</strong> {{ $kategori }}</div>
    </div>

    <p>Tim kami akan segera menindaklanjuti. Anda akan kami kabari kembali begitu ada perkembangan.</p>

    <p class="muted">Terima kasih atas laporan Anda — Sicakra selalu berusaha memberi layanan terbaik.</p>
</x-emails.layout>