<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LupaPasswordPelangganRequest;
use App\Http\Requests\Auth\ResetPasswordPelangganRequest;
use App\Models\Pelanggan;
use App\Notifications\ResetPasswordNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LupaPasswordController extends Controller
{
    /**
     * Kirim email reset password. Tanpa kenyal email — selalu 200 biar
     * pelanggan tidak bisa ketahui email mana yang terdaftar.
     */
    public function kirimLink(LupaPasswordPelangganRequest $request)
    {
        $request->validated();

        $pelanggan = Pelanggan::where('email', $request->validated('email'))->first();

        if ($pelanggan) {
            $token = Str::random(60);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $pelanggan->email],
                ['token' => $token, 'created_at' => now()],
            );

            $pelanggan->notify(new ResetPasswordNotification($token));
        }

        return response()->json([
            'message' => 'Jika email terdaftar, link reset password telah dikirim.',
        ]);
    }

    public function reset(ResetPasswordPelangganRequest $request)
    {
        $email = $request->validated('email');
        $pelanggan = Pelanggan::where('email', $email)->first();

        if (! $pelanggan) {
            throw ValidationException::withMessages([
                'email' => ['Email tidak terdaftar.'],
            ]);
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || ! hash_equals($record->token, $request->validated('token'))) {
            throw ValidationException::withMessages([
                'token' => ['Token reset tidak valid.'],
            ]);
        }

        $expired = Carbon::parse($record->created_at)->addMinutes(
            (int) config('auth.passwords.users.expire', 60)
        )->lt(now());
        if ($expired) {
            throw ValidationException::withMessages([
                'token' => ['Token reset sudah kedaluwarsa. Silakan minta ulang.'],
            ]);
        }

        $pelanggan->update([
            'password' => $request->validated('password'),
            'password_sudah_dibuat' => true,
        ]);

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Password berhasil direset. Silakan login dengan password baru.',
        ]);
    }
}