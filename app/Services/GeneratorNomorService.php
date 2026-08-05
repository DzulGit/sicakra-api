<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GeneratorNomorService
{
    /**
     * Generate nomor unik berformat {prefix}{6 digit berurutan}, mis. PMH000001.
     *
     * Dibungkus lockForUpdate() + DB Transaction agar aman dari race condition
     * saat ada 2 request submit bersamaan (mis. 2 pendaftaran masuk di detik yang sama).
     *
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $modelClass, string $kolom, string $prefix, bool $acak = false): string
    {
        return DB::transaction(function () use ($modelClass, $kolom, $prefix, $acak) {
            if ($acak) {
                // ponytail: serangkaian bilangan acak 6 digit tanpa pola, validasi
                // unik dengan query; cukup aman untuk kebutuhan sekarang.
                do {
                    $nomor = $prefix.random_int(100000, 999999);
                } while ($modelClass::lockForUpdate()->where($kolom, $nomor)->exists());

                return $nomor;
            }

            $terakhir = $modelClass::lockForUpdate()
                ->whereNotNull($kolom)
                ->orderByDesc('id')
                ->first();

            $urutan = $terakhir
                ? ((int) substr($terakhir->{$kolom}, strlen($prefix))) + 1
                : 1;

            return $prefix.str_pad((string) $urutan, 6, '0', STR_PAD_LEFT);
        });
    }
}