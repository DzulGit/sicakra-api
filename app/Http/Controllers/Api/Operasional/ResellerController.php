<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\PeranAdminEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\SimpanResellerRequest;
use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Tagihan;
use App\Repositories\Contracts\AdminRepositoryInterface;

class ResellerController extends Controller
{
    public function __construct(
        private readonly AdminRepositoryInterface $adminRepository,
    ) {}

    public function index()
    {
        $this->authorize('viewAny', Admin::class);

        $resellers = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->withCount(['pelanggan'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $resellers]);
    }

    public function store(SimpanResellerRequest $request)
    {
        $this->authorize('create', Admin::class);

        $data = $request->validated();
        $data['peran'] = PeranAdminEnum::RESELLER;
        $data['status_aktif'] = true;
        $data['dibuat_oleh'] = $request->user()->id;

        $reseller = $this->adminRepository->create($data);

        return response()->json(['data' => $reseller], 201);
    }

    public function show(Admin $reseller)
    {
        $this->authorize('view', $reseller);

        $reseller->loadCount('pelanggan');

        return response()->json(['data' => $reseller]);
    }

    /** Pantau pelanggan milik reseller — read-only (tanpa aksi tulis). */
    public function pelanggan(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $pelanggan = $reseller->pelanggan()
            ->with([
                'layananInternet.paketInternet',
                'layananInternet.tagihan.pembayaran',
            ])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $pelanggan]);
    }

    public function pelangganDetail(Admin $reseller, \App\Models\Pelanggan $pelanggan)
    {
        $this->authorize('lihatPelanggan', $reseller);

        // Pastikan pelanggan memang milik reseller tersebut.
        if ($pelanggan->reseller_id !== $reseller->id) {
            abort(404);
        }

        $pelanggan->load([
            'layananInternet.paketInternet',
            'layananInternet.tagihan.pembayaran',
        ]);

        return response()->json([
            'data' => $pelanggan,
        ]);
    }

    /** Pantau paket internet yang dibuat reseller — read-only. */
    public function paket(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $paket = PaketInternet::where('reseller_id', $reseller->id)
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $paket]);
    }

    /** Pantau seluruh tagihan reseller kepada pelanggannya — read-only. */
    public function tagihan(Admin $reseller)
    {
        $this->authorize('lihatPelanggan', $reseller);

        $tagihan = Tagihan::whereHas(
            'layananInternet.pelanggan',
            fn ($query) => $query->where('reseller_id', $reseller->id),
        )
            ->with([
                'layananInternet.pelanggan',
                'layananInternet.paketInternet',
                'pembayaran',
            ])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $tagihan]);
    }
}