<?php

namespace App\Enums;

// Status pembayaran pada TAGIHAN (bukan status transaksi mentah di tabel pembayaran)
enum StatusPembayaranEnum: string
{
    case BELUM_BAYAR = 'belum_bayar';
    case SUDAH_BAYAR = 'sudah_bayar';
    case KEDALUWARSA = 'kedaluwarsa';
}