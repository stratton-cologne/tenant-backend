@include('emails.layout', [
    'subject' => 'LDAP-Deaktivierung abgeschlossen',
    'slot' => '
        <h1 style="margin:0 0 8px 0;font-size:22px;line-height:30px;color:#111827;">LDAP-Deaktivierung abgeschlossen</h1>
        <p style="margin:0 0 14px 0;font-size:14px;line-height:22px;color:#4b5563;">
            Die Umstellung wurde erfolgreich verarbeitet.
        </p>
        <div style="margin:0 0 16px 0;border:1px solid #d1d5db;background:#f9fafb;border-radius:12px;padding:14px;">
            <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#4b5563;">
                <strong style="color:#111827;">Strategie:</strong> '.e((string) ($result['strategy'] ?? '-')).'
            </p>
            <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#4b5563;">
                <strong style="color:#111827;">Konvertiert:</strong> '.e((string) ((int) ($result['converted_count'] ?? 0))).'
            </p>
            <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#4b5563;">
                <strong style="color:#111827;">Deaktiviert:</strong> '.e((string) ((int) ($result['disabled_count'] ?? 0))).'
            </p>
            <p style="margin:0 0 8px 0;font-size:13px;line-height:20px;color:#4b5563;">
                <strong style="color:#111827;">Mailfehler:</strong> '.e((string) ((int) ($result['mail_failures'] ?? 0))).'
            </p>
            <p style="margin:0;font-size:13px;line-height:20px;color:#4b5563;">
                <strong style="color:#111827;">Abgeschlossen:</strong> '.e((string) ($result['finished_at'] ?? '-')).'
            </p>
        </div>
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">
            Diese Benachrichtigung wurde automatisch nach Abschluss der LDAP-Deaktivierung erzeugt.
        </p>
    '
])
