<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Enums\PeranAdminEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\SimpanResellerRequest;
use App\Models\Admin;
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
            ->with(['layananInternet.paketInternet'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $pelanggan]);
    }
}