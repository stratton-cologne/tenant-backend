<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MfaAppActivationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $activationUrl,
        public readonly string $activationToken,
        public readonly int $ttlMinutes,
        public readonly string $otpAuthUri,
        public readonly string $qrCodeUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('MFA App Aktivierung')
            ->view('emails.mfa_app_activation', [
                'name' => $this->name,
                'activationUrl' => $this->activationUrl,
                'activationToken' => $this->activationToken,
                'ttlMinutes' => $this->ttlMinutes,
                'otpAuthUri' => $this->otpAuthUri,
                'qrCodeUrl' => $this->qrCodeUrl,
            ]);
    }
}
