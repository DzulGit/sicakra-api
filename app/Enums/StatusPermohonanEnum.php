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

    public function label(): string
    {
        return str_replace('_', ' ', ucwords(strtolower($this->value), '_'));
    }
}