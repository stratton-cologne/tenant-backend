<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LdapDeactivationFinishedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(public array $result)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('LDAP-Deaktivierung abgeschlossen')
            ->view('emails.ldap_deactivation_finished', [
                'result' => $this->result,
            ]);
    }
}

