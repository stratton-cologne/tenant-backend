<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Tenant Portal' }}</title>
</head>
<body style="margin:0;padding:0;background:#0b1220;font-family:Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b1220;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">
                    <tr>
                        <td style="padding:0 0 14px 0;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="height:38px;width:38px;border-radius:12px;background:linear-gradient(135deg,#10b981,#22d3ee);text-align:center;vertical-align:middle;color:#0b1220;font-weight:700;">
                                        EP
                                    </td>
                                    <td style="padding-left:10px;color:#e5e7eb;font-size:14px;font-weight:700;letter-spacing:0.3px;">
                                        Enterprise Platform
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #d1d5db;border-radius:16px;padding:24px;">
                            {!! $slot ?? '' !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 4px 0 4px;color:#9ca3af;font-size:12px;line-height:18px;">
                            Diese Nachricht wurde automatisch vom Tenant Portal gesendet.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
