<?php

namespace App\Services;

use App\Models\TenantNotification;

class TenantNotificationService
{
    /**
     * @param array<string, mixed> $meta
     */
    public function notifyUserUuid(string $userUuid, string $type, string $title, ?string $message = null, array $meta = []): ?TenantNotification
    {
        $userUuid = trim($userUuid);
        if ($userUuid === '') {
            return null;
        }

        return TenantNotification::query()->create([
            'user_uuid' => $userUuid,
            'type' => trim($type) !== '' ? trim($type) : 'system.info',
            'title' => trim($title) !== '' ? trim($title) : 'Benachrichtigung',
            'message' => $message,
            'meta_json' => $meta,
            'is_read' => false,
            'is_archived' => false,
        ]);
    }

    /**
     * @param array<int, string> $userUuids
     * @param array<string, mixed> $meta
     */
    public function notifyManyUserUuids(array $userUuids, string $type, string $title, ?string $message = null, array $meta = []): void
    {
        $unique = array_values(array_unique(array_filter(array_map(
            static fn (mixed $uuid): string => is_string($uuid) ? trim($uuid) : '',
            $userUuids
        ), static fn (string $uuid): bool => $uuid !== '')));

        foreach ($unique as $userUuid) {
            $this->notifyUserUuid($userUuid, $type, $title, $message, $meta);
        }
    }
}
