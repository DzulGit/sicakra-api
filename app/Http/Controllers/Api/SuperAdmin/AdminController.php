<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Filters\AdminFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SimpanAdminRequest;
use App\Http\Requests\SuperAdmin\UbahAdminRequest;
use App\Http\Requests\SuperAdmin\ValidasiPasswordSuperAdminRequest;
use App\Models\Admin;
use App\Repositories\Contracts\AdminRepositoryInterface;
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

        if (! $this->passwordSuperAdminSesuai($data, $request->user())) {
            return $this->balasanPasswordTidakSesuai();
        }

        if ($request->filled('password_baru')) {
            $data['password'] = $data['password_baru'];
        }

        unset($data['password_superadmin']);

        $admin = $this->adminRepository->update($admin, $data);

        return response()->json(['data' => $admin]);
    }

    public function nonaktifkan(ValidasiPasswordSuperAdminRequest $request, Admin $admin)
    {
        if ($admin->id === $request->user()->id) {
            abort(403, 'Tidak bisa menonaktifkan akun sendiri.');
        }

        if (! $this->passwordSuperAdminSesuai($request->validated(), $request->user())) {
            return $this->balasanPasswordTidakSesuai();
        }

        $admin = $this->adminRepository->update($admin, ['status_aktif' => false]);

        // Cabut semua token supaya admin nonaktif tidak punya akses lagi.
        $admin->tokens()->delete();

        return response()->json(['data' => $admin, 'message' => 'Admin berhasil dinonaktifkan.']);
    }

    public function aktifkan(ValidasiPasswordSuperAdminRequest $request, Admin $admin)
    {
        if (! $this->passwordSuperAdminSesuai($request->validated(), $request->user())) {
            return $this->balasanPasswordTidakSesuai();
        }

        $admin = $this->adminRepository->update($admin, ['status_aktif' => true]);

        return response()->json(['data' => $admin, 'message' => 'Admin berhasil diaktifkan kembali.']);
    }

    private function passwordSuperAdminSesuai(array $data, Admin $superAdmin): bool
    {
        return Hash::check($data['password_superadmin'], $superAdmin->password);
    }

    private function balasanPasswordTidakSesuai(): object
    {
        return response()->json([
            'message' => 'Password super admin tidak sesuai.',
            'errors' => ['password_superadmin' => ['Password super admin tidak sesuai.']],
        ], 422);
    }
}
