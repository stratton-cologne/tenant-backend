@include('emails.layout', [
    'subject' => 'Ihr MFA-Code',
    'slot' => '
        <h1 style="margin:0 0 8px 0;font-size:22px;line-height:30px;color:#111827;">MFA-Code zur Anmeldung</h1>
        <p style="margin:0 0 18px 0;font-size:14px;line-height:22px;color:#4b5563;">
            Bitte verwenden Sie den folgenden Einmalcode, um Ihre Anmeldung abzuschliessen.
        </p>
        <div style="margin:0 0 16px 0;border:1px solid #a7f3d0;background:#ecfdf5;border-radius:12px;padding:16px;text-align:center;">
            <div style="font-size:30px;letter-spacing:6px;font-weight:700;color:#047857;">'.e($otp).'</div>
        </div>
        <p style="margin:0 0 10px 0;font-size:13px;line-height:20px;color:#4b5563;">
            Gueltigkeit: '.e($ttlMinutes).' Minuten
        </p>
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">
            Falls Sie die Anmeldung nicht selbst gestartet haben, ignorieren Sie diese E-Mail.
        </p>
    '
])
