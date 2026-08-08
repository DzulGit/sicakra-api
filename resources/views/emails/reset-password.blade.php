<x-emails.layout judul="Reset Password — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Kami menerima permintaan untuk mereset password portal pelanggan Anda.</p>

    <a class="btn" href="{{ $urlReset }}">Reset Password</a>

    <p>Link ini berlaku hingga <strong>{{ $expires }}</strong>. Jika Anda tidak merasa meminta reset password, abaikan email ini.</p>

    <p class="muted">— Tim Sicakra</p>
</x-emails.layout>