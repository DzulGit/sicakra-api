<?php

namespace App\Services;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\SiklusPenagihanService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PembayaranAllocationService
{
    public function __construct(
        private readonly SiklusPenagihanService $siklusPenagihanService,
    ) {}

    /**
     * Membuat pembayaran tunai yang langsung dianggap berhasil,
     * kemudian mengalokasikannya ke tagihan pelanggan.
     */
    public function buatPembayaranTunai(
        Pelanggan $pelanggan,
        float $jumlahDibayar,
        array $dataPembayaran = []
    ): Pembayaran {
        if ($jumlahDibayar <= 0) {
            throw new RuntimeException(
                'Jumlah pembayaran harus lebih besar dari 0.'
            );
        }

        return DB::transaction(function () use (
            $pelanggan,
            $jumlahDibayar,
            $dataPembayaran
        ) {
            $pembayaran = Pembayaran::create([
                'pelanggan_id' => $pelanggan->id,
                'tagihan_id' => null,

                'metode_pembayaran' =>
                    $dataPembayaran['metode_pembayaran'] ?? 'tunai',

                'provider' =>
                    $dataPembayaran['provider'] ?? null,

                'provider_reference' =>
                    $dataPembayaran['provider_reference'] ?? null,

                'provider_external_id' =>
                    $dataPembayaran['provider_external_id'] ?? null,

                'payment_url' =>
                    $dataPembayaran['payment_url'] ?? null,

                'provider_status' =>
                    $dataPembayaran['provider_status'] ?? null,

                'provider_expires_at' =>
                    $dataPembayaran['provider_expires_at'] ?? null,

                'dibayar_oleh' =>
                    $dataPembayaran['dibayar_oleh'] ?? null,

                'jumlah_dibayar' => $jumlahDibayar,

                'tagihan_terpilih' =>
                    $dataPembayaran['tagihan_terpilih'] ?? null,

                'status' => StatusTransaksiEnum::BERHASIL,

                'payload_webhook' =>
                    $dataPembayaran['payload_webhook'] ?? null,

                'dibayar_pada' => now(),
            ]);

            $this->selesaikanPembayaran($pembayaran);

            return $pembayaran->fresh([
                'pelanggan',
                'alokasiTagihan.tagihan',
                'mutasiSaldoKredit',
            ]);
        });
    }

    public function hitungSaldoKredit(Pelanggan $pelanggan): float
    {
        $kredit = (float) $pelanggan
            ->mutasiSaldoKredit()
            ->where('jenis', 'kredit')
            ->sum('jumlah');

        $pemakaian = (float) $pelanggan
            ->mutasiSaldoKredit()
            ->where('jenis', 'pemakaian')
            ->sum('jumlah');

        return round(
            max(0, $kredit - $pemakaian),
            2
        );
    }

    public function gunakanSaldoKredit(
        Pelanggan $pelanggan,
        ?array $tagihanIds = null
    ): array {
        return DB::transaction(function () use ($pelanggan, $tagihanIds) {
            $pelanggan = Pelanggan::query()
                ->lockForUpdate()
                ->findOrFail($pelanggan->id);

            $saldoKredit = $this->hitungSaldoKredit($pelanggan);

            if ($saldoKredit <= 0) {
                return [
                    'saldo_awal' => 0,
                    'total_digunakan' => 0,
                    'saldo_akhir' => 0,
                    'tagihan' => [],
                ];
            }

            $tagihan = Tagihan::query()
                ->whereHas(
                    'layananInternet',
                    fn ($query) => $query->where(
                        'pelanggan_id',
                        $pelanggan->id
                    )
                )
                ->where(
                    'status_pembayaran',
                    StatusPembayaranEnum::BELUM_BAYAR->value
                );

            if (is_array($tagihanIds) && count($tagihanIds) > 0) {
                $tagihan->whereIn('id', $tagihanIds);
            }

            $tagihan = $tagihan
                ->orderBy('periode_tahun')
                ->orderBy('periode_bulan')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sisaKredit = $saldoKredit;
            $totalDigunakan = 0;
            $hasil = [];

            foreach ($tagihan as $itemTagihan) {
                if ($sisaKredit <= 0) {
                    break;
                }

                $sudahDibayar = $this->hitungTotalPembayaranBerhasil(
                    $itemTagihan
                );

                $sudahDipakaiKredit = $this->hitungTotalPemakaianKredit(
                    $itemTagihan
                );

                $totalTerselesaikan = round(
                    $sudahDibayar + $sudahDipakaiKredit,
                    2
                );

                $totalTagihan = (float) $itemTagihan->total_tagihan;

                $sisaTagihan = round(
                    max(0, $totalTagihan - $totalTerselesaikan),
                    2
                );

                if ($sisaTagihan <= 0) {
                    $this->tandaiTagihanLunas($itemTagihan);
                    continue;
                }

                $jumlahPemakaian = min(
                    $sisaKredit,
                    $sisaTagihan
                );

                if ($jumlahPemakaian <= 0) {
                    continue;
                }

                MutasiSaldoKredit::create([
                    'pelanggan_id' => $pelanggan->id,
                    'pembayaran_id' => null,
                    'tagihan_id' => $itemTagihan->id,
                    'jenis' => 'pemakaian',
                    'jumlah' => round($jumlahPemakaian, 2),
                    'keterangan' =>
                        'Saldo kredit pelanggan digunakan untuk pembayaran tagihan.',
                ]);

                $sisaKredit = round(
                    $sisaKredit - $jumlahPemakaian,
                    2
                );

                $totalDigunakan = round(
                    $totalDigunakan + $jumlahPemakaian,
                    2
                );

                $totalTerselesaikan = round(
                    $totalTerselesaikan + $jumlahPemakaian,
                    2
                );

                if ($totalTerselesaikan >= $totalTagihan) {
                    $this->tandaiTagihanLunas($itemTagihan);

                    $itemTagihan->loadMissing(
                        'layananInternet.pelanggan'
                    );

                    $this->siklusPenagihanService
                        ->majukanJadwalSetelahPembayaran(
                            $itemTagihan
                        );
                } else {
                    $this->tandaiTagihanBelumLunas(
                        $itemTagihan
                    );
                }

                $hasil[] = [
                    'tagihan_id' => $itemTagihan->id,
                    'nomor_tagihan' => $itemTagihan->nomor_tagihan,
                    'jumlah_digunakan' => round(
                        $jumlahPemakaian,
                        2
                    ),
                    'sisa_tagihan' => round(
                        max(
                            0,
                            $totalTagihan - $totalTerselesaikan
                        ),
                        2
                    ),
                    'status_pembayaran' =>
                        $totalTerselesaikan >= $totalTagihan
                            ? StatusPembayaranEnum::SUDAH_BAYAR->value
                            : StatusPembayaranEnum::BELUM_BAYAR->value,
                ];
            }

            return [
                'saldo_awal' => round($saldoKredit, 2),
                'total_digunakan' => round($totalDigunakan, 2),
                'saldo_akhir' => round($sisaKredit, 2),
                'tagihan' => $hasil,
            ];
        });
    }

    private function hitungTotalPembayaranBerhasil(
        Tagihan $tagihan
    ): float {
        return round(
            (float) $tagihan
                ->alokasiPembayaran()
                ->whereHas(
                    'pembayaran',
                    fn ($query) => $query->where(
                        'status',
                        StatusTransaksiEnum::BERHASIL->value
                    )
                )
                ->sum('jumlah_dialokasikan'),
            2
        );
    }

    private function hitungTotalPemakaianKredit(
        Tagihan $tagihan
    ): float {
        return round(
            (float) MutasiSaldoKredit::query()
                ->where('tagihan_id', $tagihan->id)
                ->where('jenis', 'pemakaian')
                ->sum('jumlah'),
            2
        );
    }

    /**
     * Menyelesaikan pembayaran yang sudah BERHASIL.
     *
     * Fungsi ini dipakai oleh:
     * - pembayaran tunai
     * - webhook Xendit
     * - Finpay/Finnet nantinya
     *
     * Idempotent:
     * kalau pembayaran sudah pernah dialokasikan, tidak akan
     * dialokasikan ulang.
     *
     * $tagihanIds opsional untuk memilih tagihan mana saja yang
     * boleh dialokasikan. Kalau null, pakai pilihan yang tersimpan
     * di pembayaran.tagihan_terpilih (alur "bayar gabungan").
     */
    public function selesaikanPembayaran(
        Pembayaran $pembayaran,
        ?array $tagihanIds = null
    ): Pembayaran {
        return DB::transaction(function () use ($pembayaran, $tagihanIds) {
            $pembayaran = Pembayaran::query()
                ->lockForUpdate()
                ->findOrFail($pembayaran->id);

            if ($pembayaran->status !== StatusTransaksiEnum::BERHASIL) {
                throw new RuntimeException(
                    'Pembayaran belum berstatus berhasil.'
                );
            }

            /*
             * Idempotency:
             *
             * Kalau pembayaran sudah punya allocation atau mutasi kredit,
             * jangan proses ulang.
             */
            $sudahDiproses =
                $pembayaran->alokasiTagihan()->exists()
                || $pembayaran->mutasiSaldoKredit()->exists();

            if ($sudahDiproses) {
                return $pembayaran->fresh([
                    'pelanggan',
                    'alokasiTagihan.tagihan',
                    'mutasiSaldoKredit',
                ]);
            }

            if (!$pembayaran->pelanggan_id) {
                throw new RuntimeException(
                    'Pembayaran tidak memiliki pelanggan.'
                );
            }

            $jumlahPembayaran = (float) $pembayaran->jumlah_dibayar;

            if ($jumlahPembayaran <= 0) {
                throw new RuntimeException(
                    'Jumlah pembayaran tidak valid.'
                );
            }

            $pelanggan = Pelanggan::query()
                ->lockForUpdate()
                ->findOrFail($pembayaran->pelanggan_id);

            $sisaPembayaran = $jumlahPembayaran;

            /*
             * Ambil semua tagihan outstanding pelanggan,
             * dari periode paling lama ke paling baru.
             */
            $tagihanIds = $tagihanIds ?? $pembayaran->tagihan_terpilih;

            $tagihan = Tagihan::query()
                ->whereHas(
                    'layananInternet',
                    fn ($query) => $query->where(
                        'pelanggan_id',
                        $pelanggan->id
                    )
                )
                ->where(
                    'status_pembayaran',
                    StatusPembayaranEnum::BELUM_BAYAR->value
                );

            if (is_array($tagihanIds) && count($tagihanIds) > 0) {
                $tagihan->whereIn('id', $tagihanIds);
            }

            $tagihan = $tagihan
                ->orderBy('periode_tahun')
                ->orderBy('periode_bulan')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tagihanLunas = [];

            foreach ($tagihan as $itemTagihan) {
                if ($sisaPembayaran <= 0) {
                    break;
                }

                $sudahDibayar = $this->hitungTotalPembayaranBerhasil($itemTagihan);

                $sudahDipakaiKredit = $this->hitungTotalPemakaianKredit(
                    $itemTagihan
                );

                $totalTagihan = (float) $itemTagihan->total_tagihan;

                $totalSudahTerselesaikan = round(
                    $sudahDibayar + $sudahDipakaiKredit,
                    2
                );

                $sisaTagihan = max(
                    0,
                    $totalTagihan - $totalSudahTerselesaikan
                );

                /*
                 * Bisa terjadi data lama memiliki status belum_bayar
                 * tetapi seluruh nominal sudah dialokasikan.
                 */
                if ($sisaTagihan <= 0) {
                    $this->tandaiTagihanLunas($itemTagihan);

                    continue;
                }

                $jumlahAlokasi = min(
                    $sisaPembayaran,
                    $sisaTagihan
                );

                if ($jumlahAlokasi <= 0) {
                    continue;
                }

                $pembayaran->alokasiTagihan()->create([
                    'tagihan_id' => $itemTagihan->id,
                    'jumlah_dialokasikan' => round(
                        $jumlahAlokasi,
                        2
                    ),
                ]);

                $sisaPembayaran = round(
                    $sisaPembayaran - $jumlahAlokasi,
                    2
                );

                $totalSudahTerselesaikan = round(
                    $totalSudahTerselesaikan + $jumlahAlokasi,
                    2
                );

                if ($totalSudahTerselesaikan >= $totalTagihan) {
                    $this->tandaiTagihanLunas($itemTagihan);
                    $tagihanLunas[] = $itemTagihan;
                } else {
                    $this->tandaiTagihanBelumLunas($itemTagihan);
                }
            }

            /*
             * Kalau masih ada uang setelah seluruh tagihan outstanding
             * selesai, simpan sebagai kredit pelanggan.
             */
            if ($sisaPembayaran > 0) {
                $this->catatKredit(
                    $pelanggan,
                    $pembayaran,
                    $sisaPembayaran
                );
            }

            foreach ($tagihanLunas as $itemTagihan) {
                $itemTagihan->loadMissing('layananInternet.pelanggan');

                $this->siklusPenagihanService
                    ->majukanJadwalSetelahPembayaran($itemTagihan);
            }

            return $pembayaran->fresh([
                'pelanggan',
                'alokasiTagihan.tagihan',
                'mutasiSaldoKredit',
            ]);
        });
    }

    /**
     * Menandai tagihan sudah lunas.
     */
    private function tandaiTagihanLunas(Tagihan $tagihan): void
    {
        $tagihan->update([
            'status_pembayaran' =>
                StatusPembayaranEnum::SUDAH_BAYAR,

            'dibayar_pada' => now(),
        ]);
    }

    /**
     * Memastikan tagihan tetap belum lunas.
     *
     * Jangan mengubah total_tagihan.
     */
    private function tandaiTagihanBelumLunas(Tagihan $tagihan): void
    {
        $tagihan->update([
            'status_pembayaran' =>
                StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    /**
     * Mencatat kelebihan pembayaran sebagai kredit pelanggan.
     */
    private function catatKredit(
        Pelanggan $pelanggan,
        Pembayaran $pembayaran,
        float $jumlah
    ): void {
        if ($jumlah <= 0) {
            return;
        }

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => null,
            'jenis' => 'kredit',
            'jumlah' => round($jumlah, 2),
            'keterangan' =>
                'Kelebihan pembayaran otomatis menjadi saldo kredit pelanggan.',
        ]);
    }

    /**
     * Riwayat pembayaran yang menyentuh sebuah tagihan, diambil dari
     * alokasi (pembayaran_tagihan) karena Pembayaran.tagihan_id kini
     * nullable (satu pembayaran bisa melayani banyak tagihan).
     *
     * @return Pembayaran[]
     */
    public function riwayatPembayaranTagihan(Tagihan $tagihan): array
    {
        return $tagihan->alokasiPembayaran()
            ->with('pembayaran')
            ->orderByDesc('id')
            ->get()
            ->map(function (PembayaranTagihan $alokasi) {
                return $alokasi->pembayaran;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function hitungSisaTagihan(Tagihan $tagihan): float
    {
        $sudahDibayar = $this->hitungTotalPembayaranBerhasil(
            $tagihan
        );

        $sudahDipakaiKredit = $this->hitungTotalPemakaianKredit(
            $tagihan
        );

        return round(
            max(
                0,
                (float) $tagihan->total_tagihan
                    - $sudahDibayar
                    - $sudahDipakaiKredit
            ),
            2
        );
    }

    /**
     * Detail keterangan pembayaran sebuah tagihan (sisa, sudah
     * dibayar, saldo kredit terpakai). Sumber tunggal perhitungan
     * agar semua endpoint konsisten.
     */
    public function detailTagihan(Tagihan $tagihan): array
    {
        $sudahDibayar = $this->hitungTotalPembayaranBerhasil(
            $tagihan
        );

        $sudahDipakaiKredit = $this->hitungTotalPemakaianKredit(
            $tagihan
        );

        $totalTagihan = (float) $tagihan->total_tagihan;
        $telahTerbayar = round($sudahDibayar + $sudahDipakaiKredit, 2);
        $sisa = round($telahTerbayar - $totalTagihan, 2);
        $draft = $tagihan->status_pembayaran === StatusPembayaranEnum::BELUM_DITERBITKAN;

        return [
            'id' => $tagihan->id,
            'nomor_tagihan' => $tagihan->nomor_tagihan,
            'periode_bulan' => $tagihan->periode_bulan,
            'periode_tahun' => $tagihan->periode_tahun,
            'total_tagihan' => round($totalTagihan, 2),
            'jumlah_bulan' => $tagihan->jumlah_bulan,
            'sudah_dibayar' => round($sudahDibayar, 2),
            'saldo_kredit_digunakan' => round(
                $sudahDipakaiKredit,
                2
            ),
            'telah_terbayar' => $telahTerbayar,
            'sisa' => $sisa,
            'sisa_tagihan' => round(
                max(
                    0,
                    $totalTagihan
                        - $sudahDibayar
                        - $sudahDipakaiKredit
                ),
                2
            ),
            'status' => $draft
                ? 'Belum Diterbitkan'
                : $this->hitungStatusTagihan($tagihan, $telahTerbayar, $sisa),
            'status_pembayaran' => $tagihan->status_pembayaran->value,
            'status_tampilan' => $draft
                ? 'belum_diterbitkan'
                : ($telahTerbayar <= 0
                    ? 'belum_bayar'
                    : ($sisa >= 0
                        ? 'lunas'
                        : 'sedang_dicicil')),
            'dibayar_pada' => $tagihan->dibayar_pada?->toDateTimeString(),
            'diterbitkan_pada' => $tagihan->diterbitkan_pada?->toDateTimeString(),
            'tanggal_lunas' => $tagihan->dibayar_pada
                ? $this->waktuWib($tagihan->dibayar_pada)
                : null,
        ];
    }

    /**
     * Jumlah tagihan per status finansial untuk 1 pelanggan.
     * Draft (belum_diterbitkan) tidak dihitung — belum payable/outstanding.
     *
     * @return array{belum_bayar:int, sedang_cicil:int, tertunggak:int, lunas:int}
     */
    public function ringkasanTagihanPelanggan(Pelanggan $pelanggan): array
    {
        $ringkasan = [
            'belum_bayar' => 0,
            'sedang_cicil' => 0,
            'tertunggak' => 0,
            'lunas' => 0,
        ];

        $tagihan = Tagihan::query()
            ->whereHas('layananInternet', fn ($q) => $q->where('pelanggan_id', $pelanggan->id))
            ->where('status_pembayaran', '!=', StatusPembayaranEnum::BELUM_DITERBITKAN)
            ->get();

        foreach ($tagihan as $item) {
            $kunci = match ($this->detailTagihan($item)['status']) {
                'Belum Bayar' => 'belum_bayar',
                'Sedang Cicil' => 'sedang_cicil',
                'Tertunggak' => 'tertunggak',
                'Lunas' => 'lunas',
                default => null,
            };

            if ($kunci !== null) {
                $ringkasan[$kunci]++;
            }
        }

        return $ringkasan;
    }

    /**
     * Hitung status tagihan secara dinamis berbasis keuangan aktual.
     *
     * Precedence:
     *  1. Lunas — sisa >= 0
     *  2. Tertunggak — sisa < 0, periode lampau, layanan aktif
     *  3. Sedang Cicil — telah_terbayar > 0
     *  4. Belum Bayar — telah_terbayar == 0
     */
    private function hitungStatusTagihan(
        Tagihan $tagihan,
        float $telahTerbayar,
        float $sisa,
    ): string {
        if ($sisa >= 0) {
            return 'Lunas';
        }

        $tagihan->loadMissing('layananInternet');

        $sekarang = now('Asia/Jakarta');
        $periodeLebihLama =
            $tagihan->periode_tahun < $sekarang->year
            || (
                $tagihan->periode_tahun === $sekarang->year
                && $tagihan->periode_bulan < $sekarang->month
            );

        if (
            $periodeLebihLama
            && $tagihan->layananInternet?->status === StatusLayananEnum::AKTIF
        ) {
            return 'Tertunggak';
        }

        return $telahTerbayar > 0 ? 'Sedang Cicil' : 'Belum Bayar';
    }

    /**
     * Nomor pembayaran untuk khalayak (PAY-000123). Kolom nomor_pembayaran
     * tidak disimpan — diturunkan dari id agar tidak menambah skema.
     */
    public function nomorPembayaran(Pembayaran $pembayaran): string
    {
        return 'PAY-' . str_pad((string) $pembayaran->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Timeline per tagihan: transaksi pembayaran BERHASIL yang menyentuh
     * tagihan, plus pemakaian saldo kredit, dengan sisa tagihan setelah
     * setiap kejadian. Tambahan bersifat append-only (histori lama tidak
     * berubah ketika pembayaran baru masuk).
     *
     * @return array<int, array<string, mixed>>
     */
    public function timelinePembayaranTagihan(Tagihan $tagihan): array
    {
        $events = [];

        foreach ($tagihan->alokasiPembayaran()->with('pembayaran')->get() as $alokasi) {
            $pembayaran = $alokasi->pembayaran;

            if (
                !$pembayaran
                || $pembayaran->status->value !== StatusTransaksiEnum::BERHASIL->value
            ) {
                continue;
            }

            $waktu = $pembayaran->dibayar_pada ?? $pembayaran->created_at;

            $events[] = [
                'jenis' => 'pembayaran',
                'waktu' => $waktu,
                'urutan' => $pembayaran->id,
                'jumlah' => round((float) $alokasi->jumlah_dialokasikan, 2),
                'jumlah_dibayar' => round((float) $pembayaran->jumlah_dibayar, 2),
                'jumlah_kredit' => round(
                    MutasiSaldoKredit::query()
                        ->where('pembayaran_id', $pembayaran->id)
                        ->where('jenis', 'kredit')
                        ->sum('jumlah'),
                    2
                ),
                'nomor_pembayaran' => $this->nomorPembayaran($pembayaran),
                'pembayaran_id' => $pembayaran->id,
                'metode_pembayaran' => $pembayaran->metode_pembayaran,
                'provider' => $pembayaran->provider,
                'status' => $pembayaran->status->value,
                'keterangan' => null,
            ];
        }

        foreach (
            MutasiSaldoKredit::query()
                ->where('tagihan_id', $tagihan->id)
                ->where('jenis', 'pemakaian')
                ->get() as $mutasi
        ) {
            $events[] = [
                'jenis' => 'kredit',
                'waktu' => $mutasi->created_at,
                'urutan' => $mutasi->id,
                'jumlah' => round((float) $mutasi->jumlah, 2),
                'nomor_pembayaran' => null,
                'pembayaran_id' => null,
                'metode_pembayaran' => null,
                'provider' => null,
                'status' => 'pemakaian_kredit',
                'keterangan' => $mutasi->keterangan,
            ];
        }

        usort($events, function (array $a, array $b) {
            $ta = $a['waktu'] ? $a['waktu']->getTimestamp() : 0;
            $tb = $b['waktu'] ? $b['waktu']->getTimestamp() : 0;

            return [$ta <=> $tb, $a['urutan'] <=> $b['urutan']];
        });

        $totalTagihan = (float) $tagihan->total_tagihan;
        $sudahDibayar = 0;
        $sudahDipakaiKredit = 0;

        foreach ($events as &$event) {
            if ($event['jenis'] === 'pembayaran') {
                $sudahDibayar = round($sudahDibayar + $event['jumlah'], 2);
            } else {
                $sudahDipakaiKredit = round($sudahDipakaiKredit + $event['jumlah'], 2);
            }

            $event['sisa_setelah'] = round(
                max(0, $totalTagihan - $sudahDibayar - $sudahDipakaiKredit),
                2
            );
            $event['waktu'] = $event['waktu'] ? $this->waktuWib($event['waktu']) : null;
        }
        unset($event);

        return array_reverse($events);
    }

    /**
     * Format waktu tampilan dalam WIB (UTC+7) dari timestamp UTC.
     * Penyimpanan tetap UTC — hanya presentasi laporan yang dikonversi.
     */
    public function waktuWib($tanggalWaktu): ?string
    {
        if (!$tanggalWaktu) {
            return null;
        }

        return $tanggalWaktu
            ->timezone('Asia/Jakarta')
            ->format('d M Y, H:i:s');
    }

    /**
     * Ringkasan tunggakan pelanggan: sisa tagihan (bukan total
     * tagihan) dari semua tagihan BELUM_BAYAR yang masih punya sisa.
     */
    public function ringkasanTunggakan(Pelanggan $pelanggan): array
    {
        $tagihan = Tagihan::query()
            ->whereHas(
                'layananInternet',
                fn ($query) => $query->where(
                    'pelanggan_id',
                    $pelanggan->id
                )
            )
            ->where(
                'status_pembayaran',
                StatusPembayaranEnum::BELUM_BAYAR->value
            )
            ->orderBy('periode_tahun')
            ->orderBy('periode_bulan')
            ->orderBy('id')
            ->get();

        $total = 0;
        $detail = [];

        foreach ($tagihan as $item) {
            $data = $this->detailTagihan($item);

            if ($data['sisa_tagihan'] <= 0) {
                continue;
            }

            $total += $data['sisa_tagihan'];
            $detail[] = $data;
        }

        return [
            'total_tunggakan' => round($total, 2),
            'jumlah_tagihan' => count($detail),
            'tagihan' => $detail,
        ];
    }
}