<x-emails.layout judul="Status Permohonan — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Status permohonan Anda dengan nomor <strong>{{ $nomorPermohonan }}</strong> telah diperbarui menjadi:</p>

    <div class="meta">
        <div><strong>Status:</strong> {{ $status }}</div>
    </div>

    @if($catatan)
        <p><strong>Catatan:</strong> {{ $catatan }}</p>
    @endif

    <p>Silakan pantau informasi lebih lanjut melalui portal pelanggan Sicakra.</p>

    <p class="muted">Terima kasih telah menggunakan layanan Sicakra.</p>
</x-emails.layout>