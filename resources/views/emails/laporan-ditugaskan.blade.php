<x-emails.layout judul="Laporan Ditugaskan — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Sebuah laporan kendala telah ditugaskan kepada Anda untuk ditindaklanjuti.</p>

    <div class="meta">
        <div><strong>Nomor Laporan:</strong> {{ $nomorLaporan }}</div>
        <div><strong>Kategori:</strong> {{ $kategori }}</div>
        <div><strong>Pelanggan:</strong> {{ $pelanggan }}</div>
    </div>

    <p><strong>Deskripsi:</strong> {{ $deskripsi }}</p>

    <p>Mohon segera tindak lanjuti sesuai prosedur dan laporkan hasil penanganan Anda.</p>

    <p class="muted">— Tim Sicakra</p>
</x-emails.layout>