<?php

namespace App\Http\Middleware;

use App\Models\TenantSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApplyTenantMailSettingsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->applyMailSettingsSafely();

        return $next($request);
    }

    private function applyMailSettingsSafely(): void
    {
        try {
            $mail = TenantSetting::query()->where('key', 'mail')->value('value_json');
            if (!is_array($mail)) {
                return;
            }

            $defaultMailer = $this->stringValue($mail, 'mailer');
            if ($defaultMailer !== null) {
                config(['mail.default' => $defaultMailer]);
            }

            $fromAddress = $this->stringValue($mail, 'from_address');
            $fromName = $this->stringValue($mail, 'from_name');
            if ($fromAddress !== null) {
                config(['mail.from.address' => $fromAddress]);
            }
            if ($fromName !== null) {
                config(['mail.from.name' => $fromName]);
            }

            $replyTo = $this->stringValue($mail, 'reply_to');
            if ($replyTo !== null) {
                config(['mail.reply_to.address' => $replyTo]);
                config(['mail.reply_to.name' => $fromName ?? (string) config('mail.from.name')]);
            }

            $smtp = (array) config('mail.mailers.smtp', []);
            $smtp['host'] = $this->stringValue($mail, 'host') ?? ($smtp['host'] ?? null);
            $smtp['port'] = $this->intValue($mail, 'port') ?? ($smtp['port'] ?? null);
            $smtp['encryption'] = $this->stringValue($mail, 'encryption') ?? ($smtp['encryption'] ?? null);

            $useAuth = $this->boolValue($mail, 'use_auth');
            if ($useAuth === false) {
                $smtp['username'] = null;
                $smtp['password'] = null;
            } else {
                $smtp['username'] = $this->stringValue($mail, 'username') ?? ($smtp['username'] ?? null);
                $smtpPassword = $this->stringValue($mail, 'password');
                if ($smtpPassword !== null) {
                    $smtp['password'] = $smtpPassword;
                }
            }

            config(['mail.mailers.smtp' => $smtp]);
        } catch (Throwable) {
            // Ignore dynamic mail config errors to avoid breaking request handling.
        }
    }

    private function stringValue(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            return null;
        }

        $value = trim($data[$key]);

        return $value === '' ? null : $value;
    }

    private function intValue(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        return filter_var($data[$key], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    }

    private function boolValue(array $data, string $key): ?bool
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}

