<x-emails.layout judul="Reminder Tagihan — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Kami ingatkan, tagihan internet Anda akan segera jatuh tempo.</p>

    <div class="meta">
        <div><strong>Nomor Tagihan:</strong> {{ $nomorTagihan }}</div>
        <div><strong>Total:</strong> Rp {{ $total }}</div>
        <div><strong>Jatuh Tempo:</strong> {{ $jatuhTempo }}</div>
    </div>

    <p>Lakukan pembayaran sebelum jatuh tempo agar layanan internet Anda tetap aktif tanpa gangguan.</p>

    @if($urlBayar)
        <a class="btn" href="{{ $urlBayar }}">Bayar Sekarang</a>
    @endif

    <p class="muted">Terima kasih — Tim Sicakra</p>
</x-emails.layout>