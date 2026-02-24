<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TemporaryPasswordMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $temporaryPassword,
        public readonly string $loginUrl,
        public readonly string $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Ihr temporaeres Passwort')
            ->view('emails.temporary_password', [
                'name' => $this->name,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl,
                'expiresAt' => $this->expiresAt,
            ]);
    }
}
