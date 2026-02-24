<?php

namespace App\Services\Auth;

use App\Mail\MfaOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class MailOtpService
{
    public function issueOtpForUser(User $user): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttlMinutes = (int) config('security.mfa_code_ttl_minutes', 10);

        Mail::to($user->email)->send(new MfaOtpMail($otp, $ttlMinutes));

        return $otp;
    }
}
