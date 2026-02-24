<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MfaOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly int $ttlMinutes,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Ihr MFA-Code')
            ->view('emails.mfa_otp', [
                'otp' => $this->otp,
                'ttlMinutes' => $this->ttlMinutes,
            ]);
    }
}
