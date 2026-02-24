@include('emails.layout', [
    'subject' => 'MFA App Aktivierung',
    'slot' => '
        <h1 style="margin:0 0 8px 0;font-size:22px;line-height:30px;color:#111827;">MFA App aktivieren</h1>
        <p style="margin:0 0 16px 0;font-size:14px;line-height:22px;color:#4b5563;">
            Hallo '.e($name).',<br>bitte schliessen Sie die Aktivierung Ihrer Authenticator-App ab.
        </p>
        <div style="margin:0 0 16px 0;text-align:center;">
            <img src="'.e($qrCodeUrl).'" alt="MFA QR Code" style="display:inline-block;height:180px;width:180px;border:1px solid #d1d5db;border-radius:10px;padding:8px;background:#ffffff;">
        </div>
        <p style="margin:0 0 14px 0;font-size:13px;line-height:20px;color:#4b5563;">Oder oeffnen Sie den Aktivierungslink:</p>
        <p style="margin:0 0 16px 0;">
            <a href="'.e($activationUrl).'" style="display:inline-block;background:linear-gradient(135deg,#10b981,#22d3ee);color:#0b1220;text-decoration:none;font-weight:700;border-radius:10px;padding:10px 16px;">
                Aktivierungslink oeffnen
            </a>
        </p>
        <p style="margin:0 0 10px 0;font-size:13px;line-height:20px;color:#4b5563;">
            Token: <span style="font-family:monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:6px;">'.e($activationToken).'</span>
        </p>
        <p style="margin:0 0 10px 0;font-size:13px;line-height:20px;color:#4b5563;">
            Gueltigkeit: '.e($ttlMinutes).' Minuten
        </p>
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">
            Falls diese Aktion nicht von Ihnen stammt, ignorieren Sie diese E-Mail.
        </p>
    '
])
