<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Exports\PembayaranHistoryExport;
use App\Filters\PembayaranFilter;
use App\Http\Controllers\Controller;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class PembayaranController extends Controller
{
    /** Scope reseller (null = admin keuangan melihat pelanggan non-reseller). */
    protected ?int $resellerId = null;

    private const NAMA_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const LABEL_STATUS_TAGIHAN = [
        'belum_bayar' => 'Belum Bayar',
        'sedang_dicicil' => 'Sedang Dicicil',
        'lunas' => 'Lunas',
    ];

    public function __construct(
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    /**
     * Daftar pembayaran (transaksi) dengan alokasi per tagihan.
     * Filter lewat PembayaranFilter (status, periode, pelanggan, no tagihan,
     * no pembayaran, metode, provider, reseller_id, status_tagihan).
     */
    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        $data = (new PembayaranFilter($request))
            ->apply($query)
            ->with([
                'pelanggan:id,nama_lengkap,nomor_pelanggan',
                'alokasiTagihan.tagihan',
                'mutasiSaldoKredit',
            ])
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        $data->getCollection()->transform(
            fn (Pembayaran $pembayaran) => $this->sajikanPembayaran($pembayaran)
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Detail SATU pembayaran: info transaksi + alokasi lengkap per tagihan
     * (dengan finansial tagihan) + saldo kredit yang terbentuk dari transaksi ini.
     */
    public function show(Request $request, Pembayaran $pembayaran)
    {
        $this->pastikanScope($pembayaran);

        $pembayaran->load([
            'pelanggan:id,nama_lengkap,nomor_pelanggan',
            'alokasiTagihan.tagihan',
            'mutasiSaldoKredit',
        ]);

        return response()->json(['data' => $this->sajikanPembayaran($pembayaran, detailTagihan: true)]);
    }

    /**
     * Saldo kredit / deposit pelanggan beserta ledger mutasinya
     * (MutasiSaldoKredit = sumber kebenaran, bukan kolom balance).
     */
    public function kredit(Request $request, Pelanggan $pelanggan)
    {
        $this->pastikanPelangganDiScope($pelanggan);

        $mutasi = $pelanggan
            ->mutasiSaldoKredit()
            ->with(['pembayaran', 'tagihan'])
            ->orderBy('created_at')
            ->get();

        $sisa = 0;
        $items = $mutasi->map(function (MutasiSaldoKredit $m) use (&$sisa) {
            $sisa = round($sisa + (float) $m->jumlah, 2);

            return [
                'id' => $m->id,
                'jenis' => $m->jenis,
                'jumlah' => round((float) $m->jumlah, 2),
                'keterangan' => $m->keterangan,
                'waktu_wib' => $this->pembayaranAllocationService->waktuWib($m->created_at),
                'nomor_pembayaran' => $m->pembayaran
                    ? $this->pembayaranAllocationService->nomorPembayaran($m->pembayaran)
                    : null,
                'nomor_tagihan' => $m->tagihan?->nomor_tagihan,
                'saldo_setelah' => round($sisa, 2),
            ];
        });

        return response()->json([
            'data' => [
                'pelanggan' => [
                    'id' => $pelanggan->id,
                    'nama_lengkap' => $pelanggan->nama_lengkap,
                    'nomor_pelanggan' => $pelanggan->nomor_pelanggan,
                ],
                'saldo_deposit' => round(
                    $this->pembayaranAllocationService->hitungSaldoKredit($pelanggan),
                    2
                ),
                'mutasi' => $items->reverse()->values(),
            ],
        ]);
    }

    /**
     * Export Excel: satu baris per alokasi pembayaran_tagihan.
     * Nominal dikirim numerik agar bisa di-SUM/pivot.
     */
    public function laporanExcel(Request $request)
    {
        $rows = $this->barisLaporan($request);

        $file = Excel::raw(new PembayaranHistoryExport($rows), \Maatwebsite\Excel\Excel::XLSX);

        return response($file, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="riwayat-pembayaran.xlsx"',
        ]);
    }

    /**
     * Export PDF: dikelompokkan per tagihan agar mudah dibaca.
     */
    public function laporanPdf(Request $request)
    {
        $query = $this->baseQuery($request);

        $pembayaran = (new PembayaranFilter($request))
            ->apply($query)
            ->with(['pelanggan:id,nama_lengkap', 'alokasiTagihan.tagihan'])
            ->orderBy('id')
            ->get();

        $service = $this->pembayaranAllocationService;
        $perTagihan = [];

        foreach ($pembayaran as $item) {
            foreach ($item->alokasiTagihan as $alokasi) {
                $tagihan = $alokasi->tagihan;

                if (!$tagihan) {
                    continue;
                }

                $perTagihan[$tagihan->id]['tagihan'] = $tagihan;
                $perTagihan[$tagihan->id]['masuk'][] = [
                    'nomor_pembayaran' => $service->nomorPembayaran($item),
                    'waktu_wib' => $service->waktuWib($item->dibayar_pada ?? $item->created_at),
                    'jumlah' => round((float) $alokasi->jumlah_dialokasikan, 2),
                    'metode_pembayaran' => $item->metode_pembayaran,
                    'provider' => $item->provider,
                    'status' => $item->status->value,
                ];
            }
        }

        $data = collect($perTagihan)
            ->map(function (array $group) use ($service) {
                $detail = $service->detailTagihan($group['tagihan']);

                $masuk = collect($group['masuk'])
                    ->sortByDesc('waktu_wib')
                    ->values()
                    ->all();

                return [
                    'nomor_tagihan' => $group['tagihan']->nomor_tagihan,
                    'periode' => $this->labelPeriodeTagihan($group['tagihan']),
                    'detail' => $detail,
                    'masuk' => $masuk,
                ];
            })
            ->values();

        $pdf = Pdf::loadView('pdf.laporan-pembayaran', [
            'perTagihan' => $data,
            'labelPeriode' => $this->labelPeriodeFilter($request),
        ]);

        return $pdf->stream('riwayat-pembayaran.pdf');
    }

    // ─── Bantu ─────────────────────────────────────────────────────

    protected function baseQuery(Request $request)
    {
        $query = Pembayaran::query();

        if ($this->resellerId) {
            return $query->whereHas(
                'pelanggan',
                fn ($q) => $q->where('reseller_id', $this->resellerId)
            );
        }

        if ($request->filled('reseller_id')) {
            return $query->whereHas(
                'pelanggan',
                fn ($q) => $q->where('reseller_id', $request->integer('reseller_id'))
            );
        }

        return $query->where(function ($q) {
            $q->whereHas('pelanggan', fn ($q2) => $q2->whereNull('reseller_id'))
                ->orWhereNull('pelanggan_id');
        });
    }

    protected function pastikanScope(Pembayaran $pembayaran): void
    {
        if (!$this->resellerId) {
            return;
        }

        $pembayaran->loadMissing('pelanggan');

        if ($pembayaran->pelanggan?->reseller_id !== $this->resellerId) {
            abort(404, 'Pembayaran tidak ditemukan.');
        }
    }

    protected function pastikanPelangganDiScope(Pelanggan $pelanggan): void
    {
        if (!$this->resellerId) {
            return;
        }

        if ($pelanggan->reseller_id !== $this->resellerId) {
            abort(404, 'Pelanggan tidak ditemukan.');
        }
    }

    private function sajikanPembayaran(
        Pembayaran $pembayaran,
        bool $detailTagihan = false,
    ): array {
        $service = $this->pembayaranAllocationService;

        $alokasi = $pembayaran->alokasiTagihan
            ->map(function (PembayaranTagihan $alokasi) use ($service, $detailTagihan) {
                $item = [
                    'id' => $alokasi->id,
                    'tagihan_id' => $alokasi->tagihan_id,
                    'jumlah_dialokasikan' => round((float) $alokasi->jumlah_dialokasikan, 2),
                    'nomor_tagihan' => $alokasi->tagihan?->nomor_tagihan,
                    'periode_tagihan' => $alokasi->tagihan
                        ? $this->labelPeriodeTagihan($alokasi->tagihan)
                        : null,
                ];

                if ($detailTagihan && $alokasi->tagihan) {
                    $item['tagihan'] = $service->detailTagihan($alokasi->tagihan);
                }

                return $item;
            })
            ->filter(fn ($item) => $item['tagihan_id'] !== null && $item['nomor_tagihan'] !== null)
            ->values();

        $kreditTerbentuk = (float) $pembayaran
            ->mutasiSaldoKredit
            ->where('jenis', 'kredit')
            ->sum('jumlah');

        return [
            'id' => $pembayaran->id,
            'nomor_pembayaran' => $service->nomorPembayaran($pembayaran),
            'pelanggan' => $pembayaran->pelanggan
                ? [
                    'id' => $pembayaran->pelanggan->id,
                    'nama_lengkap' => $pembayaran->pelanggan->nama_lengkap,
                    'nomor_pelanggan' => $pembayaran->pelanggan->nomor_pelanggan,
                ]
                : null,
            'jumlah_dibayar' => round((float) $pembayaran->jumlah_dibayar, 2),
            'metode_pembayaran' => $pembayaran->metode_pembayaran,
            'provider' => $pembayaran->provider,
            'provider_reference' => $pembayaran->provider_reference,
            'provider_external_id' => $pembayaran->provider_external_id,
            'status' => $pembayaran->status->value,
            'dibayar_oleh' => $pembayaran->dibayar_oleh,
            'pakai_saldo_kredit' => (bool) $pembayaran->pakai_saldo_kredit,
            'waktu_wib' => $service->waktuWib($pembayaran->dibayar_pada ?? $pembayaran->created_at),
            'dibayar_pada' => $pembayaran->dibayar_pada?->toDateTimeString(),
            'created_at' => $pembayaran->created_at?->toDateTimeString(),
            'total_alokasi' => round((float) $alokasi->sum('jumlah_dialokasikan'), 2),
            'saldo_kredit_terbentuk' => round($kreditTerbentuk, 2),
            'alokasi_tagihan' => $alokasi->all(),
        ];
    }

    private function barisLaporan(Request $request): array
    {
        $query = $this->baseQuery($request);

        $pembayaran = (new PembayaranFilter($request))
            ->apply($query)
            ->with(['pelanggan', 'alokasiTagihan.tagihan'])
            ->orderBy('id')
            ->get();

        $service = $this->pembayaranAllocationService;
        $rows = [];

        foreach ($pembayaran as $item) {
            foreach ($item->alokasiTagihan as $alokasi) {
                $tagihan = $alokasi->tagihan;

                if (!$tagihan) {
                    continue;
                }

                $detail = $service->detailTagihan($tagihan);
                $waktu = $this->wib($item->dibayar_pada ?? $item->created_at);

                $statusTagihan = $detail['status_pembayaran'] === 'belum_diterbitkan'
                    ? 'Belum Diterbitkan'
                    : (self::LABEL_STATUS_TAGIHAN[$detail['status_tampilan']] ?? $detail['status_tampilan']);

                $rows[] = [
                    $waktu?->format('d/m/Y'),
                    $waktu?->format('H:i:s'),
                    $service->nomorPembayaran($item),
                    $tagihan->nomor_tagihan,
                    $item->pelanggan?->nama_lengkap,
                    $this->labelPeriodeTagihan($tagihan),
                    round((float) $detail['total_tagihan'], 2),
                    round((float) $item->jumlah_dibayar, 2),
                    round((float) $alokasi->jumlah_dialokasikan, 2),
                    round((float) $detail['sudah_dibayar'], 2),
                    round((float) $detail['sisa_tagihan'], 2),
                    $statusTagihan,
                    $detail['tanggal_lunas'],
                    $item->metode_pembayaran,
                    $item->provider,
                    $item->status->value,
                    $item->provider_reference,
                    $item->provider_external_id,
                    $item->dibayar_oleh,
                ];
            }
        }

        return $rows;
    }

    private function labelPeriodeTagihan(Tagihan $tagihan): string
    {
        $bulan = self::NAMA_BULAN[$tagihan->periode_bulan]
            ?? "Bulan {$tagihan->periode_bulan}";

        return "{$bulan} {$tagihan->periode_tahun}";
    }

    private function labelPeriodeFilter(Request $request): string
    {
        if (!$request->filled('dari') && !$request->filled('sampai')) {
            return 'Semua Periode';
        }

        return trim(($request->query('dari') ?? '') . ' — ' . ($request->query('sampai') ?? ''));
    }

    private function wib($tanggalWaktu): ?Carbon
    {
        if (!$tanggalWaktu) {
            return null;
        }

        return $tanggalWaktu->timezone('Asia/Jakarta');
    }
}