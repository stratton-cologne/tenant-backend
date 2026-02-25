<?php

namespace App\Jobs;

use App\Mail\LdapDeactivationFinishedMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ProcessLdapDeactivationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, string> $selectedUserUuids
     */
    public function __construct(
        public string $operationId,
        public string $strategy,
        public array $selectedUserUuids,
        public int $tempPasswordValidDays,
        public ?string $actorUserUuid = null,
    ) {
    }

    public function handle(TenantNotificationService $tenantNotificationService): void
    {
        $this->setOperationStatus('running', ['started_at' => now()->toISOString()]);

        try {
            $activeAdUsers = User::query()
                ->where('auth_provider', 'ad_ldap')
                ->where('is_active', true)
                ->get();
            $activeByUuid = $activeAdUsers->keyBy('uuid');

            foreach ($this->selectedUserUuids as $uuid) {
                if (!$activeByUuid->has($uuid)) {
                    throw new \RuntimeException('Selected users must be active AD users');
                }
            }

            $convertUsers = collect();
            $disableUsers = collect();
            if ($this->strategy === 'disable_all_ad_users') {
                $disableUsers = $activeAdUsers;
            } elseif ($this->strategy === 'convert_all_to_local') {
                $convertUsers = $activeAdUsers;
            } elseif ($this->strategy === 'convert_selected_to_local_and_disable_rest') {
                if ($this->selectedUserUuids === []) {
                    throw new \RuntimeException('No users selected for conversion');
                }
                $convertUsers = $activeAdUsers->filter(fn (User $user): bool => in_array((string) $user->uuid, $this->selectedUserUuids, true))->values();
                $disableUsers = $activeAdUsers->reject(fn (User $user): bool => in_array((string) $user->uuid, $this->selectedUserUuids, true))->values();
            } else {
                throw new \RuntimeException('Unknown strategy');
            }

            $convertedMailPayloads = [];
            DB::transaction(function () use ($convertUsers, $disableUsers, &$convertedMailPayloads): void {
                foreach ($convertUsers as $user) {
                    $tempPassword = Str::password(14, true, true, false, false);
                    $expiresAt = now()->addDays($this->tempPasswordValidDays);
                    $user->password = Hash::make($tempPassword);
                    $user->auth_provider = 'local';
                    $user->must_change_password = true;
                    $user->temp_password_expires_at = $expiresAt;
                    $user->is_active = true;
                    $user->disabled_at = null;
                    $user->external_directory_active = false;
                    $user->save();

                    $convertedMailPayloads[] = [
                        'email' => (string) $user->email,
                        'name' => (string) $user->full_name,
                        'temp_password' => $tempPassword,
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                    ];
                }

                foreach ($disableUsers as $user) {
                    $user->is_active = false;
                    $user->disabled_at = now();
                    $user->external_directory_active = false;
                    $user->save();
                }

                TenantSetting::query()->updateOrCreate(['key' => 'auth_provider'], ['value_json' => 'local']);
                $config = TenantSetting::query()->where('key', 'ad_ldap_config')->value('value_json');
                if (!is_array($config)) {
                    $config = [];
                }
                $config['enabled'] = false;
                TenantSetting::query()->updateOrCreate(['key' => 'ad_ldap_config'], ['value_json' => $config]);
            });

            $portalUrl = (string) env('TENANT_PORTAL_URL', (string) config('app.url'));
            $loginUrl = rtrim($portalUrl, '/');
            $mailFailures = 0;
            foreach ($convertedMailPayloads as $mailPayload) {
                try {
                    Mail::to((string) $mailPayload['email'])->send(new TemporaryPasswordMail(
                        name: (string) $mailPayload['name'],
                        temporaryPassword: (string) $mailPayload['temp_password'],
                        loginUrl: $loginUrl,
                        expiresAt: (string) $mailPayload['expires_at'],
                    ));
                } catch (Throwable $exception) {
                    $mailFailures++;
                    Log::warning('LDAP deactivation conversion mail failed', [
                        'email' => (string) $mailPayload['email'],
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $result = [
                'strategy' => $this->strategy,
                'converted_count' => $convertUsers->count(),
                'disabled_count' => $disableUsers->count(),
                'mail_failures' => $mailFailures,
                'finished_at' => now()->toISOString(),
            ];

            $adminUsers = User::query()
                ->where('is_active', true)
                ->whereHas('roles.permissions', function ($query): void {
                    $query->whereIn('name', ['users.manage', 'settings.manage']);
                })
                ->get(['uuid', 'email', 'first_name', 'last_name']);
            $adminUuids = $adminUsers->pluck('uuid')->map(fn ($uuid): string => (string) $uuid)->all();
            $adminEmails = $adminUsers->pluck('email')->map(fn ($email): string => (string) $email)->filter()->unique()->values()->all();

            $tenantNotificationService->notifyManyUserUuids(
                $adminUuids,
                'settings.auth.ldap.deactivated.finished',
                'LDAP-Deaktivierung abgeschlossen',
                sprintf(
                    'Strategie: %s, konvertiert: %d, deaktiviert: %d, Mailfehler: %d',
                    $this->strategy,
                    $convertUsers->count(),
                    $disableUsers->count(),
                    $mailFailures,
                ),
                $result
            );

            foreach ($adminEmails as $email) {
                try {
                    Mail::to($email)->send(new LdapDeactivationFinishedMail($result));
                } catch (Throwable $exception) {
                    Log::warning('LDAP deactivation admin mail failed', [
                        'email' => $email,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $this->setOperationStatus('completed', $result);
        } catch (Throwable $exception) {
            Log::error('LDAP deactivation job failed', [
                'operation_id' => $this->operationId,
                'message' => $exception->getMessage(),
            ]);
            $this->setOperationStatus('failed', [
                'error' => $exception->getMessage(),
                'finished_at' => now()->toISOString(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function setOperationStatus(string $status, array $meta = []): void
    {
        $payload = [
            'operation_id' => $this->operationId,
            'status' => $status,
            'strategy' => $this->strategy,
            'selected_user_uuids' => $this->selectedUserUuids,
            'temp_password_valid_days' => $this->tempPasswordValidDays,
            'actor_user_uuid' => $this->actorUserUuid,
            'updated_at' => now()->toISOString(),
            'meta' => $meta,
        ];
        TenantSetting::query()->updateOrCreate(
            ['key' => 'ldap_deactivation_operation:'.$this->operationId],
            ['value_json' => $payload]
        );
    }
}
