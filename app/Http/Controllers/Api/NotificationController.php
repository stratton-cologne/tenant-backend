<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        $payload = $request->validate([
            'scope' => ['nullable', 'in:all,unread,archived'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $scope = (string) ($payload['scope'] ?? 'all');
        $perPage = (int) ($payload['per_page'] ?? 20);

        $query = TenantNotification::query()
            ->where('user_uuid', (string) $user->uuid)
            ->orderByDesc('created_at');

        if ($scope === 'unread') {
            $query->where('is_archived', false)->where('is_read', false);
        } elseif ($scope === 'archived') {
            $query->where('is_archived', true);
        } else {
            $query->where('is_archived', false);
        }

        $rows = $query->paginate($perPage)->through(fn (TenantNotification $notification): array => $this->serialize($notification));

        return response()->json($rows);
    }

    public function recent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        $payload = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $limit = (int) ($payload['limit'] ?? 5);

        $rows = TenantNotification::query()
            ->where('user_uuid', (string) $user->uuid)
            ->where('is_archived', false)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (TenantNotification $notification): array => $this->serialize($notification))
            ->values();

        $unreadCount = TenantNotification::query()
            ->where('user_uuid', (string) $user->uuid)
            ->where('is_archived', false)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'data' => [
                'items' => $rows,
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function stream(Request $request)
    {
        abort_unless($this->notificationsStreamEnabled(), 404, 'Notification stream disabled');

        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        $payload = $request->validate([
            'last_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $lastId = (int) ($payload['last_id'] ?? 0);

        return response()->stream(function () use ($user, $lastId): void {
            @set_time_limit(0);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $cursor = $lastId;
            $startedAt = time();
            $maxLifetimeSeconds = 20;

            while (!connection_aborted() && (time() - $startedAt) < $maxLifetimeSeconds) {
                @set_time_limit(0);
                $rows = TenantNotification::query()
                    ->where('user_uuid', (string) $user->uuid)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(50)
                    ->get();

                foreach ($rows as $notification) {
                    $cursor = max($cursor, (int) $notification->id);
                    $payload = $this->serialize($notification);
                    echo "event: notification\n";
                    echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                }

                echo "event: heartbeat\n";
                echo "data: {\"cursor\":{$cursor}}\n\n";
                flush();
                usleep(1000000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function notificationsStreamEnabled(): bool
    {
        return filter_var((string) env('NOTIFICATIONS_STREAM_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function update(Request $request, TenantNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        abort_unless((string) $notification->user_uuid === (string) $user->uuid, 404, 'Notification not found');

        $payload = $request->validate([
            'is_read' => ['nullable', 'boolean'],
            'is_archived' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_read', $payload)) {
            $isRead = (bool) $payload['is_read'];
            $notification->is_read = $isRead;
            $notification->read_at = $isRead ? now() : null;
        }

        if (array_key_exists('is_archived', $payload)) {
            $isArchived = (bool) $payload['is_archived'];
            $notification->is_archived = $isArchived;
            $notification->archived_at = $isArchived ? now() : null;
        }

        $notification->save();

        return response()->json([
            'data' => $this->serialize($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        TenantNotification::query()
            ->where('user_uuid', (string) $user->uuid)
            ->where('is_archived', false)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, TenantNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        abort_unless((string) $notification->user_uuid === (string) $user->uuid, 404, 'Notification not found');

        $notification->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TenantNotification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'uuid' => (string) $notification->uuid,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'message' => $notification->message,
            'meta' => is_array($notification->meta_json) ? $notification->meta_json : [],
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)->toISOString(),
            'is_archived' => (bool) $notification->is_archived,
            'archived_at' => optional($notification->archived_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
            'updated_at' => optional($notification->updated_at)->toISOString(),
        ];
    }
}
