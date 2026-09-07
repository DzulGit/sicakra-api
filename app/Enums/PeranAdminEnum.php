<?php

namespace App\Enums;

enum PeranAdminEnum: string
{
    case SUPER_ADMIN = 'super_admin';
    case OPERASIONAL = 'operasional';
    case TEKNISI = 'teknisi';
    case KEUANGAN = 'keuangan';
    case RESELLER = 'reseller';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::OPERASIONAL => 'Operasional',
            self::TEKNISI => 'Teknisi',
            self::KEUANGAN => 'Keuangan',
            self::RESELLER => 'Reseller',
        };
    }
}