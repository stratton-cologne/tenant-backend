<?php

namespace App\Services\Auth;

use JsonException;
use RuntimeException;

class JwtService
{
    /**
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims, ?int $ttlMinutes = null): string
    {
        $now = time();
        $ttl = $ttlMinutes ?? (int) config('security.jwt_ttl_minutes', 480);
        $kid = $this->activeKid();

        if (!isset($claims['jti'])) {
            $claims['jti'] = bin2hex(random_bytes(16));
        }

        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + max(60, $ttl * 60),
            'iss' => (string) config('app.url'),
        ]);

        return $this->encode($payload, $kid);
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid JWT encoding');
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported JWT algorithm');
        }

        $kid = (string) ($header['kid'] ?? '');
        $expectedSignature = $this->sign("{$encodedHeader}.{$encodedPayload}", $kid);
        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new RuntimeException('Invalid JWT signature');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp <= 0 || $exp < time()) {
            throw new RuntimeException('JWT expired');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload, string $kid): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => $kid];
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $encodedSignature = $this->sign("{$encodedHeader}.{$encodedPayload}", $kid);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    private function sign(string $data, string $kid): string
    {
        $keys = $this->signingKeys();
        $secret = (string) ($keys[$kid] ?? '');
        if ($secret === '') {
            throw new RuntimeException('Missing JWT secret for kid '.$kid);
        }

        return $this->base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function activeKid(): string
    {
        return (string) config('security.jwt_active_kid', 'default');
    }

    /**
     * @return array<string, string>
     */
    private function signingKeys(): array
    {
        $json = (string) config('security.jwt_keys_json', '');
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                return collect($decoded)
                    ->filter(fn ($value, $key): bool => is_string($key) && is_string($value) && $value !== '')
                    ->map(fn ($value): string => (string) $value)
                    ->all();
            }
        }

        return [
            $this->activeKid() => (string) config('security.jwt_secret'),
        ];
    }
}
