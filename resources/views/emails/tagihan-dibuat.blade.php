<x-emails.layout judul="Tagihan Baru — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Tagihan baru telah diterbitkan untuk layanan internet Anda.</p>

    <div class="meta">
        <div><strong>Nomor Tagihan:</strong> {{ $nomorTagihan }}</div>
        <div><strong>Nomor Layanan:</strong> {{ $nomorLayanan }}</div>
        <div><strong>Paket:</strong> {{ $paket }}</div>
        <div><strong>Periode:</strong> {{ $periode }}</div>
        <div><strong>Total:</strong> Rp {{ $total }}</div>
        <div><strong>Jatuh Tempo:</strong> {{ $jatuhTempo }}</div>
    </div>

    @if($urlBayar)
        <a class="btn" href="{{ $urlBayar }}">Bayar Sekarang</a>
    @else
        <p>Silakan selesaikan pembayaran sebelum tanggal jatuh tempo agar layanan tetap aktif.</p>
    @endif

    <p class="muted">Terima kasih telah menggunakan layanan Sicakra.</p>
</x-emails.layout>