{{--
    Layout email inti Sicakra. Ringan, mobile-friendly, tanpa template Laravel default.
    Warna mengikuti brand: teal (hue 186) sebagai primary, hitam pekat sebagai aksen.
--}}
<!DOCTYPE html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $judul ?? 'Sicakra ISP' }}</title>
    <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
    <style>
        body { margin: 0; padding: 0; background: #f5f6f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse; }
        .container { width: 100%; max-width: 600px; margin: 0 auto; }
        .preheader { display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; }
        .header { background: #0f172a; padding: 28px 32px; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 600; letter-spacing: -0.02em; color: #ffffff; }
        .header p { margin: 2px 0 0; font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase; color: #5eead4; }
        .body { background: #ffffff; border-radius: 8px 8px 0 0; padding: 32px; }
        .footer { background: #ffffff; border-radius: 0 0 8px 8px; padding: 0 24px 20px; }
        .footer .line { border-top: 1px solid #e2e8f0; padding-top: 16px; text-align: center; font-size: 12px; color: #94a3b8; }
        h1 { margin: 0 0 12px; font-size: 20px; color: #0f172a; }
        p { margin: 0 0 12px; font-size: 15px; line-height: 1.5; color: #475569; }
        .btn { display: inline-block; background: #0f172a; color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: 600; padding: 12px 22px; border-radius: 6px; margin: 8px 0 16px; }
        .meta { background: #f8fafc; border-left: 3px solid #14b8a6; padding: 12px 16px; border-radius: 4px; margin: 12px 0; font-size: 14px; color: #334155; }
        .meta strong { color: #0f172a; }
        .muted { color: #94a3b8; font-size: 13px; }
        a { color: #14b8a6; }
    </style>
</head>
<body>
    <div class="preview">{{ $judul ?? 'Sicakra ISP' }}</div>
    <center>
        <table class="container" role="presentation" cellpadding="0" cellspacing="0">
            <tr>
                <td class="header">
                    <p class="brand">PT Aqrapana Daya Mandiri</p>
                    <div class="brand-name">Sicakra</div>
                </td>
            </tr>
            <tr>
                <td class="body">
                    {{ $slot }}
                </td>
            </tr>
            <tr>
                <td class="footer">
                    <div class="line">
                        Sicakra ISP &bull; PT Aqrapana Daya Mandiri<br>
                        {{ date('Y') }} &copy; Sicakra &mdash; Fast. Stable. Reliable.
                    </div>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>