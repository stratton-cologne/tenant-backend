<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Events\Tickets\TicketChangedEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Requests\Tickets\TicketAssignRequest;
use App\Http\Requests\Tickets\TicketStatusUpdateRequest;
use App\Http\Requests\Tickets\TicketStoreRequest;
use App\Http\Requests\Tickets\TicketUpdateRequest;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketCategory;
use App\Models\Tickets\TicketQueue;
use App\Models\Tickets\TicketTag;
use App\Models\Tickets\TicketType;
use App\Models\User;
use App\Policies\Tickets\TicketPolicy;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketSlaService;
use App\Services\Tickets\TicketTenantContext;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketsController extends Controller
{
    use ResolvesTicketContext;

    private const TRANSITIONS = [
        'new' => ['triage', 'closed'],
        'triage' => ['in_progress', 'waiting', 'closed'],
        'in_progress' => ['waiting', 'resolved', 'closed'],
        'waiting' => ['in_progress', 'resolved', 'closed'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => [],
    ];

    public function index(Request $request, TicketTenantContext $tenantContext, TicketSlaService $slaService): JsonResponse
    {
        $user = $this->authUser($request);
        $permissions = $this->permissions($request);
        abort_unless((new TicketPolicy())->viewAny($user, $permissions), 403, 'Missing permission: tickets.read');

        $tenantId = $this->ticketTenantId($tenantContext);
        $query = Ticket::query()
            ->with(['assignee:id,uuid,first_name,last_name', 'queue:id,uuid,name', 'type:id,uuid,name', 'category:id,uuid,name'])
            ->where('tenant_id', $tenantId);

        if ($search = trim((string) $request->query('filter.search', $request->query('search', '')))) {
            $query->where(function ($q) use ($search): void {
                $q->where('ticket_no', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        foreach (['status', 'priority'] as $field) {
            $value = $request->query('filter.'.$field, $request->query($field));
            if (is_string($value) && $value !== '') {
                $query->where($field, $value);
            }
        }

        $sort = (string) $request->query('sort', '-created_at');
        $sortFields = explode(',', $sort);
        foreach ($sortFields as $sortFieldRaw) {
            $sortFieldRaw = trim($sortFieldRaw);
            if ($sortFieldRaw === '') {
                continue;
            }
            $direction = str_starts_with($sortFieldRaw, '-') ? 'desc' : 'asc';
            $sortField = ltrim($sortFieldRaw, '-');
            if (!in_array($sortField, ['created_at', 'updated_at', 'priority', 'status', 'ticket_no'], true)) {
                continue;
            }
            $query->orderBy($sortField, $direction);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $rows = $query->paginate($perPage)->through(fn (Ticket $ticket): array => $this->serializeTicket($ticket, $tenantId, $slaService));

        return response()->json($rows);
    }

    public function store(
        TicketStoreRequest $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $activityLogger,
        TicketSlaService $slaService
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $user = $this->authUser($request);
        $payload = $request->validated();

        $ticket = new Ticket();
        $ticket->tenant_id = $tenantId;
        $ticket->ticket_no = $this->nextTicketNo($tenantId);
        $ticket->title = (string) $payload['title'];
        $ticket->description = isset($payload['description']) ? (string) $payload['description'] : null;
        $ticket->priority = (string) ($payload['priority'] ?? 'medium');
        $ticket->status = 'new';
        $ticket->reporter_user_id = $user->id;
        $ticket->queue_id = $this->resolveQueueId($tenantId, $payload['queue_uuid'] ?? null);
        $ticket->type_id = $this->resolveTypeId($tenantId, $payload['type_uuid'] ?? null);
        $ticket->category_id = $this->resolveCategoryId($tenantId, $payload['category_uuid'] ?? null);
        $ticket->save();

        $this->syncTags($ticket, $tenantId, (array) ($payload['tag_uuids'] ?? []));

        $activityLogger->log($user, 'tickets.ticket.created', [
            'ticket_uuid' => (string) $ticket->uuid,
            'ticket_no' => (string) $ticket->ticket_no,
        ]);

        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'ticket.created', [
            'ticket_no' => (string) $ticket->ticket_no,
            'status' => (string) $ticket->status,
        ]));

        $ticket->load(['assignee:id,uuid,first_name,last_name', 'queue:id,uuid,name', 'type:id,uuid,name', 'category:id,uuid,name', 'tags:id,uuid,name,slug,color']);

        return response()->json([
            'data' => $this->serializeTicket($ticket, $tenantId, $slaService),
        ], 201);
    }

    public function show(Ticket $ticket, Request $request, TicketTenantContext $tenantContext, TicketSlaService $slaService): JsonResponse
    {
        $tenantId = $this->ticketTenantId($tenantContext);
        $this->ensureTenant($ticket, $tenantId);

        $user = $this->authUser($request);
        $permissions = $this->permissions($request);
        abort_unless((new TicketPolicy())->view($user, $ticket, $permissions), 403, 'Missing permission: tickets.read');

        $ticket->load([
            'assignee:id,uuid,first_name,last_name',
            'reporter:id,uuid,first_name,last_name',
            'queue:id,uuid,name',
            'type:id,uuid,name',
            'category:id,uuid,name',
            'tags:id,uuid,name,slug,color',
            'watchers:id,uuid,ticket_id,user_id,email,mode,created_at',
            'comments.user:id,uuid,first_name,last_name',
            'attachments.versions',
        ]);

        return response()->json([
            'data' => $this->serializeTicket($ticket, $tenantId, $slaService, true),
        ]);
    }

    public function update(
        Ticket $ticket,
        TicketUpdateRequest $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $activityLogger,
        TicketSlaService $slaService
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $this->ensureTenant($ticket, $tenantId);
        $payload = $request->validated();
        $user = $this->authUser($request);

        foreach (['title', 'description', 'priority'] as $field) {
            if (array_key_exists($field, $payload)) {
                $ticket->{$field} = $payload[$field];
            }
        }
        if (array_key_exists('queue_uuid', $payload)) {
            $ticket->queue_id = $this->resolveQueueId($tenantId, $payload['queue_uuid']);
        }
        if (array_key_exists('type_uuid', $payload)) {
            $ticket->type_id = $this->resolveTypeId($tenantId, $payload['type_uuid']);
        }
        if (array_key_exists('category_uuid', $payload)) {
            $ticket->category_id = $this->resolveCategoryId($tenantId, $payload['category_uuid']);
        }

        $ticket->save();
        if (array_key_exists('tag_uuids', $payload)) {
            $this->syncTags($ticket, $tenantId, (array) $payload['tag_uuids']);
        }

        $activityLogger->log($user, 'tickets.ticket.updated', ['ticket_uuid' => (string) $ticket->uuid]);
        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'ticket.updated'));

        $ticket->load(['assignee:id,uuid,first_name,last_name', 'queue:id,uuid,name', 'type:id,uuid,name', 'category:id,uuid,name', 'tags:id,uuid,name,slug,color']);

        return response()->json([
            'data' => $this->serializeTicket($ticket, $tenantId, $slaService),
        ]);
    }

    public function destroy(
        Ticket $ticket,
        Request $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $activityLogger
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $this->ensureTenant($ticket, $tenantId);
        $user = $this->authUser($request);
        $permissions = $this->permissions($request);
        abort_unless((new TicketPolicy())->delete($user, $ticket, $permissions), 403, 'Missing permission: tickets.delete');

        $ticketUuid = (string) $ticket->uuid;
        $ticket->delete();

        $activityLogger->log($user, 'tickets.ticket.deleted', ['ticket_uuid' => $ticketUuid]);
        event(new TicketChangedEvent($tenantId, $ticketUuid, 'ticket.updated', ['deleted' => true]));

        return response()->json(['status' => 'ok']);
    }

    public function updateStatus(
        Ticket $ticket,
        TicketStatusUpdateRequest $request,
        TicketTenantContext $tenantContext,
        TicketSlaService $slaService,
        TicketActivityLogger $activityLogger,
        TenantNotificationService $notificationService
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $this->ensureTenant($ticket, $tenantId);

        $fromStatus = (string) $ticket->status;
        $toStatus = (string) $request->validated('status');
        abort_if(!$this->canTransition($fromStatus, $toStatus), 422, 'Invalid status transition');

        $slaService->applyOnStatusChange($ticket, $fromStatus, $toStatus);
        $ticket->status = $toStatus;
        if (in_array($toStatus, ['resolved', 'closed'], true) && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }
        $ticket->save();

        $activityLogger->log($this->authUser($request), 'tickets.ticket.status_changed', [
            'ticket_uuid' => (string) $ticket->uuid,
            'from' => $fromStatus,
            'to' => $toStatus,
        ]);
        $actorUuid = (string) $this->authUser($request)->uuid;
        $notificationService->notifyManyUserUuids(
            $this->ticketRecipientUuids($ticket, $actorUuid),
            'tickets.status_changed',
            'Ticket-Status aktualisiert',
            sprintf('%s wurde von %s auf %s gesetzt.', (string) $ticket->ticket_no, $fromStatus, $toStatus),
            [
                'ticket_uuid' => (string) $ticket->uuid,
                'ticket_no' => (string) $ticket->ticket_no,
                'from' => $fromStatus,
                'to' => $toStatus,
            ]
        );

        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'ticket.status_changed', [
            'from' => $fromStatus,
            'to' => $toStatus,
        ]));

        return response()->json([
            'data' => $this->serializeTicket($ticket->fresh(['assignee:id,uuid,first_name,last_name']), $tenantId, $slaService),
        ]);
    }

    public function assign(
        Ticket $ticket,
        TicketAssignRequest $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $activityLogger,
        TicketSlaService $slaService,
        TenantNotificationService $notificationService
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $this->ensureTenant($ticket, $tenantId);
        $payload = $request->validated();

        $ticket->assignee_user_id = $this->resolveUserId($payload['assignee_user_uuid'] ?? null);
        if (array_key_exists('queue_uuid', $payload)) {
            $ticket->queue_id = $this->resolveQueueId($tenantId, $payload['queue_uuid']);
        }
        $ticket->save();

        $activityLogger->log($this->authUser($request), 'tickets.ticket.assigned', [
            'ticket_uuid' => (string) $ticket->uuid,
            'assignee_user_id' => $ticket->assignee_user_id,
            'queue_id' => $ticket->queue_id,
        ]);
        if ($ticket->assignee) {
            $notificationService->notifyUserUuid(
                (string) $ticket->assignee->uuid,
                'tickets.assigned',
                'Ticket zugewiesen',
                sprintf('Dir wurde %s zugewiesen.', (string) $ticket->ticket_no),
                [
                    'ticket_uuid' => (string) $ticket->uuid,
                    'ticket_no' => (string) $ticket->ticket_no,
                ]
            );
        }

        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'ticket.assigned', [
            'assignee_user_id' => $ticket->assignee_user_id,
            'queue_id' => $ticket->queue_id,
        ]));

        return response()->json([
            'data' => $this->serializeTicket($ticket->fresh(['assignee:id,uuid,first_name,last_name', 'queue:id,uuid,name']), $tenantId, $slaService),
        ]);
    }

    private function ensureTenant(Ticket $ticket, string $tenantId): void
    {
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
    }

    /**
     * @return array<int, string>
     */
    private function ticketRecipientUuids(Ticket $ticket, string $excludeUserUuid = ''): array
    {
        $watcherUuids = $ticket->watchers()
            ->whereNotNull('user_id')
            ->with('user:id,uuid')
            ->get()
            ->map(static fn ($watcher): string => (string) ($watcher->user?->uuid ?? ''))
            ->filter(static fn (string $uuid): bool => $uuid !== '')
            ->values()
            ->all();

        $base = array_filter([
            (string) optional($ticket->reporter)->uuid,
            (string) optional($ticket->assignee)->uuid,
            ...$watcherUuids,
        ], static fn (string $uuid): bool => $uuid !== '');

        $unique = array_values(array_unique($base));
        if ($excludeUserUuid === '') {
            return $unique;
        }

        return array_values(array_filter($unique, static fn (string $uuid): bool => $uuid !== $excludeUserUuid));
    }

    private function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    private function nextTicketNo(string $tenantId): string
    {
        $count = Ticket::query()->where('tenant_id', $tenantId)->count() + 1;
        return sprintf('TKT-%06d', $count);
    }

    private function resolveQueueId(string $tenantId, mixed $uuid): ?int
    {
        if (!is_string($uuid) || trim($uuid) === '') {
            return null;
        }
        return TicketQueue::query()->where('tenant_id', $tenantId)->where('uuid', trim($uuid))->value('id');
    }

    private function resolveTypeId(string $tenantId, mixed $uuid): ?int
    {
        if (!is_string($uuid) || trim($uuid) === '') {
            return null;
        }
        return TicketType::query()->where('tenant_id', $tenantId)->where('uuid', trim($uuid))->value('id');
    }

    private function resolveCategoryId(string $tenantId, mixed $uuid): ?int
    {
        if (!is_string($uuid) || trim($uuid) === '') {
            return null;
        }
        return TicketCategory::query()->where('tenant_id', $tenantId)->where('uuid', trim($uuid))->value('id');
    }

    private function resolveUserId(mixed $uuid): ?int
    {
        if (!is_string($uuid) || trim($uuid) === '') {
            return null;
        }
        return User::query()->where('uuid', trim($uuid))->value('id');
    }

    /**
     * @param array<int, string> $tagUuids
     */
    private function syncTags(Ticket $ticket, string $tenantId, array $tagUuids): void
    {
        $ids = TicketTag::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('uuid', $tagUuids)
            ->pluck('id')
            ->all();
        $ticket->tags()->sync($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTicket(Ticket $ticket, string $tenantId, TicketSlaService $slaService, bool $withRelations = false): array
    {
        $base = [
            'uuid' => (string) $ticket->uuid,
            'ticket_no' => (string) $ticket->ticket_no,
            'tenant_id' => (string) $ticket->tenant_id,
            'title' => (string) $ticket->title,
            'description' => $ticket->description,
            'status' => (string) $ticket->status,
            'priority' => (string) $ticket->priority,
            'first_response_at' => optional($ticket->first_response_at)?->toISOString(),
            'resolved_at' => optional($ticket->resolved_at)?->toISOString(),
            'created_at' => optional($ticket->created_at)?->toISOString(),
            'updated_at' => optional($ticket->updated_at)?->toISOString(),
            'queue' => $ticket->queue ? ['uuid' => (string) $ticket->queue->uuid, 'name' => (string) $ticket->queue->name] : null,
            'type' => $ticket->type ? ['uuid' => (string) $ticket->type->uuid, 'name' => (string) $ticket->type->name] : null,
            'category' => $ticket->category ? ['uuid' => (string) $ticket->category->uuid, 'name' => (string) $ticket->category->name] : null,
            'assignee' => $ticket->assignee ? [
                'uuid' => (string) $ticket->assignee->uuid,
                'name' => trim((string) $ticket->assignee->first_name.' '.(string) $ticket->assignee->last_name),
            ] : null,
            'sla' => $slaService->evaluate($ticket, $tenantId),
        ];

        if (!$withRelations) {
            return $base;
        }

        return $base + [
            'reporter' => $ticket->reporter ? [
                'uuid' => (string) $ticket->reporter->uuid,
                'name' => trim((string) $ticket->reporter->first_name.' '.(string) $ticket->reporter->last_name),
            ] : null,
            'tags' => $ticket->tags->map(fn (TicketTag $tag): array => [
                'uuid' => (string) $tag->uuid,
                'name' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'color' => $tag->color,
            ])->values()->all(),
            'watchers' => $ticket->watchers->map(fn ($watcher): array => [
                'uuid' => (string) $watcher->uuid,
                'user_id' => $watcher->user_id,
                'email' => $watcher->email,
                'mode' => (string) $watcher->mode,
            ])->values()->all(),
            'comments' => $ticket->comments->map(fn ($comment): array => [
                'uuid' => (string) $comment->uuid,
                'body' => (string) $comment->body,
                'source' => (string) $comment->source,
                'is_public' => (bool) $comment->is_public,
                'author' => $comment->user ? trim((string) $comment->user->first_name.' '.(string) $comment->user->last_name) : null,
                'created_at' => optional($comment->created_at)?->toISOString(),
            ])->values()->all(),
            'attachments' => $ticket->attachments->map(function ($attachment): array {
                return [
                    'uuid' => (string) $attachment->uuid,
                    'file_name' => (string) $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => (int) $attachment->size_bytes,
                    'current_version' => (int) $attachment->current_version,
                    'versions' => $attachment->versions->map(fn ($version): array => [
                        'uuid' => (string) $version->uuid,
                        'version_no' => (int) $version->version_no,
                        'file_name' => (string) $version->file_name,
                        'mime_type' => $version->mime_type,
                        'size_bytes' => (int) $version->size_bytes,
                        'created_at' => optional($version->created_at)?->toISOString(),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
