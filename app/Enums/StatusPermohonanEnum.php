<?php

namespace App\Enums;

enum StatusPermohonanEnum: string
{
    case MENUNGGU_VERIFIKASI = 'MENUNGGU_VERIFIKASI';
    case PERLU_REVISI = 'PERLU_REVISI';
    case DITERIMA = 'DITERIMA';
    case DITOLAK = 'DITOLAK';
    case DIJADWALKAN = 'DIJADWALKAN';
    case DITUNDA = 'DITUNDA';
    case DIKONVERSI = 'DIKONVERSI';

    /**
     * State machine (revisi Sept 2026) — survey & pemasangan digabung jadi satu
     * tahap kunjungan teknisi. Permohonan baru langsung masuk MENUNGGU_VERIFIKASI
     * (ditangani Operasional), bukan lewat pengecekan lokasi teknisi. DITUNDA
     * berarti "ada kendala di kunjungan sebelumnya", lalu dijadwalkan ulang jadi
     * DIJADWALKAN lagi — bukan lompat ke tahap lain.
     */
    public function transisiValid(): array
    {
        return match ($this) {
            self::MENUNGGU_VERIFIKASI => [self::PERLU_REVISI, self::DITERIMA, self::DITOLAK],
            self::PERLU_REVISI => [self::MENUNGGU_VERIFIKASI],
            self::DITERIMA => [self::DIJADWALKAN],
            self::DIJADWALKAN => [self::DITUNDA, self::DIKONVERSI],
            self::DITUNDA => [self::DIJADWALKAN],
            self::DITOLAK, self::DIKONVERSI => [],
        };
    }

    /**
     * Urutan tampil daftar Permohonan Layanan — DAFTAR INI, bukan urutan enum.
     * Operasional butuh melihat antrean kerja yang masih menunggu aksi lebih dulu:
     * 1. verifikasi  2. jadwal  3. selesai  4. ditolak
     * Di dalam satu tahap tetap created_at desc (terbaru dulu).
     */
    public static function urutanTahap(): array
    {
        return [
            self::MENUNGGU_VERIFIKASI->value,
            self::PERLU_REVISI->value,
            self::DITERIMA->value,
            self::DIJADWALKAN->value,
            self::DITUNDA->value,
            self::DIKONVERSI->value,
            self::DITOLAK->value,
        ];
    }

    public function label(): string
    {
        return str_replace('_', ' ', ucwords(strtolower($this->value), '_'));
    }
}