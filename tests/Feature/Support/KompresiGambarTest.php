<?php

namespace Tests\Feature\Support;

use App\Support\KompresiGambar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KompresiGambarTest extends TestCase
{
    /** Bagian magic bytes kontainer WebP: 'RIFF' + 'WEBP' di byte 0 dan 8. */
    private const WEBP_MAGIC = "\x52\x49\x46\x46";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function buatFileJpegAsli(string $nama = 'foto.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sicakra');
        $gambar = imagecreatetruecolor(640, 480);
        imagejpeg($gambar, $tmp);
        imagedestroy($gambar);

        return new UploadedFile($tmp, $nama, 'image/jpeg', null, true);
    }

    public function test_foto_jpeg_otomatis_dikompresi_menjadi_webp(): void
    {
        $path = KompresiGambar::simpanKeWebp($this->buatFileJpegAsli(), 'ktp');

        $this->assertStringEndsWith('.webp', $path);
        $this->assertTrue(Storage::disk('public')->exists($path));

        $isi = Storage::disk('public')->get($path);
        $this->assertStringStartsWith(self::WEBP_MAGIC, $isi);
        $this->assertStringContainsString('WEBP', substr($isi, 8, 4));
    }

    public function test_hasil_webp_lebih_ringan_dari_jpeg_asal(): void
    {
        // JPEG buatan kualitas tinggi (imagejpeg default quality 75) berukuran
        // jauh lebih besar daripada versi WebP 80 dari gambar senada.
        $tmp = tempnam(sys_get_temp_dir(), 'sicakra');
        $besar = imagecreatetruecolor(1200, 1200);
        // isi piksel acak agar sulit dikompresi (kasus terburuk)
        for ($y = 0; $y < 1200; $y += 4) {
            for ($x = 0; $x < 1200; $x += 4) {
                imagesetpixel($besar, $x, $y, imagecolorallocate($besar, $x % 256, $y % 256, ($x * $y) % 256));
            }
        }
        imagejpeg($besar, $tmp, 100);
        imagedestroy($besar);

        $file = new UploadedFile($tmp, 'noisy.jpg', 'image/jpeg', null, true);
        $path = KompresiGambar::simpanKeWebp($file, 'dokumentasi-pekerjaan');

        $this->assertGreaterThan(
            Storage::disk('public')->size($path),
            filesize($tmp),
        );
    }

    public function test_format_tak_dikenal_gd_disimpan_mentah_tanpa_gagal(): void
    {
        // Bukan gambar (mis. HEIC/scanner dgn konten tak dikenal) — "foto.jpg"
        // hanya nama; isinya 12 byte random, GD menolak men-decode-nya.
        $bukanGambar = UploadedFile::fake()->create('foto.jpg', 12);

        $path = KompresiGambar::simpanKeWebp($bukanGambar, 'ktp');

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertFalse(str_ends_with($path, '.webp'));
    }
}
