<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GeneratorNomorService
{
    /**
     * Generate nomor unik berformat {prefix}{6 digit berurutan}.
     *
     * Contoh:
     * INV000001
     * INV000002
     *
     * Sequence dihitung berdasarkan nomor dengan prefix yang sama,
     * bukan berdasarkan ID record terakhir.
     *
     * @param class-string<Model> $modelClass
     */
    public function generate(
        string $modelClass,
        string $kolom,
        string $prefix,
        bool $acak = false
    ): string {
        return DB::transaction(function () use ($modelClass, $kolom, $prefix, $acak) {
            if ($acak) {
                do {
                    $nomor = $prefix . random_int(100000, 999999);
                } while (
                    $modelClass::lockForUpdate()
                        ->where($kolom, $nomor)
                        ->exists()
                );

                return $nomor;
            }

            $query = $modelClass::query()
                ->whereNotNull($kolom);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->whereRaw(
                    $kolom . " ~ ?",
                    ['^' . preg_quote($prefix, '/') . '[0-9]{6}$']
                );
            } else {
                $query->whereRaw(
                    $kolom . " GLOB ?",
                    [$prefix . '[0-9][0-9][0-9][0-9][0-9][0-9]']
                );
            }

            $terakhir = $query
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $urutan = $terakhir
                ? ((int) substr($terakhir->{$kolom}, strlen($prefix))) + 1
                : 1;

            return $prefix . str_pad(
                (string) $urutan,
                6,
                '0',
                STR_PAD_LEFT
            );
        });
    }
}
