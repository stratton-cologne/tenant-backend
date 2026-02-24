<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SettingsTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function build(): self
    {
        return $this
            ->subject('Tenant Portal Testmail')
            ->view('emails.settings_test_mail');
    }
}
