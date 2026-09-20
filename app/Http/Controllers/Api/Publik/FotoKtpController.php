<?php

namespace App\Http\Controllers\Api\Publik;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FotoKtpController extends Controller
{
    public function tampilkan(string $file): Response
    {
        // Basename mencegah traversal keluar folder ktp/.
        $nama = basename($file);
        $path = 'ktp/'.$nama;

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $isi = Crypt::decryptString(
            Storage::disk('public')->get($path)
        );

        $mime = match (strtolower(pathinfo($nama, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return response($isi, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="'.$nama.'"');
    }
}
