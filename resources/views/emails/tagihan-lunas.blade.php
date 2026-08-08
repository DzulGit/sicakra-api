<x-emails.layout judul="Pembayaran Diterima — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Terima kasih! Pembayaran tagihan Anda telah kami terima.</p>

    <div class="meta">
        <div><strong>Nomor Tagihan:</strong> {{ $nomorTagihan }}</div>
        <div><strong>Total Dibayar:</strong> Rp {{ $total }}</div>
        <div><strong>Dibayar Pada:</strong> {{ $dibayarPada }}</div>
    </div>

    <p>Layanan internet Anda tetap aktif. Terima kasih atas pembayaran tepat waktu Anda!</p>

    <p class="muted">— Tim Sicakra</p>
</x-emails.layout>