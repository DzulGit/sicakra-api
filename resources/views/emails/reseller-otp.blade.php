<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi Ganti Email</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #f8fafc; border-radius: 8px; padding: 30px;">
        <h1 style="color: #1e293b; margin-top: 0;">Kode Verifikasi Ganti Email</h1>
        
        <p>Halo <strong>{{ $namaLengkap }}</strong>,</p>
        
        <p>Anda meminta untuk mengganti email akun reseller. Gunakan kode OTP di bawah ini untuk memverifikasi email baru Anda:</p>
        
        <div style="background: #1e293b; color: #f8fafc; padding: 20px; border-radius: 6px; text-align: center; margin: 20px 0;">
            <span style="font-size: 32px; font-weight: bold; letter-spacing: 4px;">{{ $otpCode }}</span>
        </div>
        
        <p style="color: #64748b; font-size: 14px;">
            Kode ini berlaku selama <strong>10 menit</strong>. Jika Anda tidak meminta perubahan ini, abaikan email ini.
        </p>
        
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
        
        <p style="color: #94a3b8; font-size: 12px;">
            Email ini dikirim otomatis, mohon tidak membalas.
        </p>
    </div>
</body>
</html>