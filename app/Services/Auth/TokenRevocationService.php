<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

class TokenRevocationService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function revoke(array $payload): void
    {
        $jti = (string) ($payload['jti'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        if ($jti === '' || $exp <= time()) {
            return;
        }

        $ttlSeconds = max(1, $exp - time());
        Cache::put($this->cacheKey($jti), true, now()->addSeconds($ttlSeconds));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function isRevoked(array $payload): bool
    {
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '') {
            return true;
        }

        return Cache::has($this->cacheKey($jti));
    }

    private function cacheKey(string $jti): string
    {
        return 'auth:revoked:'.$jti;
    }
}
