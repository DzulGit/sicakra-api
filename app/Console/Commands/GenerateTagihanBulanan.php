<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateTagihanBulanan extends Command
{
    protected $signature = 'tagihan:generate-bulanan';

    protected $description = 'Wrapper: dispatch tagihan:generate-draft untuk generate draft tagihan tanggal 1.';

    public function handle(): int
    {
        $exitCode = $this->call('tagihan:generate-draft');

        return $exitCode;
    }
}
