@include('emails.layout', [
    'subject' => 'Ihr temporaeres Passwort',
    'slot' => '
        <h1 style="margin:0 0 8px 0;font-size:22px;line-height:30px;color:#111827;">Willkommen im Tenant Portal</h1>
        <p style="margin:0 0 14px 0;font-size:14px;line-height:22px;color:#4b5563;">
            Hallo '.e($name).',<br>fuer Ihr Konto wurde ein temporaeres Passwort erstellt.
        </p>
        <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#4b5563;">Temporäres Passwort:</p>
        <div style="margin:0 0 16px 0;border:1px solid #d1d5db;background:#f9fafb;border-radius:12px;padding:14px;text-align:center;">
            <span style="font-family:monospace;font-size:22px;font-weight:700;letter-spacing:1px;color:#111827;">'.e($temporaryPassword).'</span>
        </div>
        <p style="margin:0 0 16px 0;font-size:13px;line-height:20px;color:#4b5563;">Gueltig bis: '.e($expiresAt).'</p>
        <p style="margin:0 0 16px 0;">
            <a href="'.e($loginUrl).'" style="display:inline-block;background:linear-gradient(135deg,#10b981,#22d3ee);color:#0b1220;text-decoration:none;font-weight:700;border-radius:10px;padding:10px 16px;">
                Zum Login
            </a>
        </p>
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">
            Bei der ersten Anmeldung muessen Sie Ihr Passwort aendern und eine MFA-Methode auswaehlen.
        </p>
    '
])
