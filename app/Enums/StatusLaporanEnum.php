<?php

namespace App\Enums;

enum StatusLaporanEnum: string
{
    case MENUNGGU = 'menunggu';
    case DIPROSES = 'diproses';
    case DITUGASKAN = 'ditugaskan';
    case SELESAI = 'selesai';
    case DITUTUP = 'ditutup';

    /**
     * State machine. Operasional bisa langsung DITUTUP dari MENUNGGU/DIPROSES
     * (mis. laporan duplikat / bukan gangguan teknis), tanpa lewat Teknisi.
     */
    public function transisiValid(): array
    {
        return match ($this) {
            self::MENUNGGU => [self::DIPROSES, self::DITUGASKAN, self::DITUTUP],
            self::DIPROSES => [self::DITUGASKAN, self::DITUTUP],
            self::DITUGASKAN => [self::SELESAI],
            self::SELESAI => [self::DITUTUP],
            self::DITUTUP => [], // status akhir
        };
    }
}