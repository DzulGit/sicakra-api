<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Filters\AdminFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SimpanAdminRequest;
use App\Http\Requests\SuperAdmin\UbahAdminRequest;
use App\Models\Admin;
use App\Repositories\Contracts\AdminRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminRepositoryInterface $adminRepository,
    ) {}

    public function index(AdminFilter $filter)
    {
        return response()->json([
            'data' => $this->adminRepository->paginate($filter),
        ]);
    }

    public function show(Admin $admin)
    {
        return response()->json(['data' => $admin]);
    }

    public function store(SimpanAdminRequest $request)
    {
        $data = $request->validated();

        $data['dibuat_oleh'] = $request->user()->id;

        $admin = $this->adminRepository->create($data);

        return response()->json(['data' => $admin], 201);
    }

    public function update(UbahAdminRequest $request, Admin $admin)
    {
        $data = $request->validated();

        if ($request->filled('password_baru')) {
            if (! Hash::check($data['password_lama'], $admin->password)) {
                return response()->json(['message' => 'Password lama tidak sesuai.', 'errors' => ['password_lama' => ['Password lama tidak sesuai.']]], 422);
            }
            $data['password'] = $data['password_baru'];
        }

        $admin = $this->adminRepository->update($admin, $data);

        return response()->json(['data' => $admin]);
    }

    public function nonaktifkan(Request $request, Admin $admin)
    {
        if ($admin->id === $request->user()->id) {
            abort(403, 'Tidak bisa menonaktifkan akun sendiri.');
        }

        $admin = $this->adminRepository->update($admin, ['status_aktif' => false]);

        return response()->json(['data' => $admin, 'message' => 'Admin berhasil dinonaktifkan.']);
    }
}
