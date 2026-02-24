<?php

namespace Tests\Feature;

use App\Services\Auth\PasswordPolicyService;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_strong_password_is_accepted(): void
    {
        $service = new PasswordPolicyService();
        self::assertTrue($service->validate('StrongPass123'));
    }
}
