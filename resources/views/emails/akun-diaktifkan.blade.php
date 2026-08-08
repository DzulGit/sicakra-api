<x-emails.layout judul="Akun Aktif — Sicakra">
    <h1>Halo, {{ $nama }}!</h1>
    <p>Selamat! Pemasangan layanan internet Anda telah selesai dan akun portal pelanggan Anda kini aktif.</p>

    <div class="meta">
        <div><strong>Nomor Pelanggan:</strong> {{ $nomorPelanggan }}</div>
        <div><strong>Username:</strong> {{ $username }}</div>
        <div><strong>Password Awal:</strong> {{ $passwordDefault }}</div>
    </div>

    <p>Gunakan kredensial di atas untuk masuk ke portal pelanggan. Segera ganti password Anda setelah login pertama untuk keamanan.</p>

    <p class="muted">Selamat menikmati layanan internet Sicakra — Fast. Stable. Reliable.</p>
</x-emails.layout>