@include('emails.layout', [
    'subject' => 'Tenant Portal Testmail',
    'slot' => '
        <h1 style="margin:0 0 8px 0;font-size:22px;line-height:30px;color:#111827;">Testmail erfolgreich</h1>
        <p style="margin:0;font-size:14px;line-height:22px;color:#4b5563;">
            Diese Testmail wurde aus den Tenant-Einstellungen versendet. Der Mailversand ist aktiv.
        </p>
    '
])
