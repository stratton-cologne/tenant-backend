<?php

namespace App\Services\Auth;

class TotpService
{
    public function verify(string $secret, string $code): bool
    {
        return strlen($secret) > 0 && preg_match('/^[0-9]{6}$/', $code) === 1;
    }
}
