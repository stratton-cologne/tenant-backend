<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TicketTaxonomyRequest;
use App\Models\Tickets\TicketCategory;
use App\Models\Tickets\TicketQueue;
use App\Models\Tickets\TicketTag;
use App\Models\Tickets\TicketType;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketTaxonomyController extends Controller
{
    use ResolvesTicketContext;

    public function indexTypes(Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        return $this->indexForModel($request, $tenantContext, TicketType::class, 'tickets.type.manage');
    }

    public function storeType(TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->storeForModel($request, $tenantContext, TicketType::class, 'tickets.type.manage', $logger, 'tickets.type');
    }

    public function updateType(TicketType $item, TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->updateForModel($item, $request, $tenantContext, 'tickets.type.manage', $logger, 'tickets.type');
    }

    public function destroyType(TicketType $item, Request $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->destroyForModel($item, $request, $tenantContext, 'tickets.type.manage', $logger, 'tickets.type');
    }

    public function indexCategories(Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        return $this->indexForModel($request, $tenantContext, TicketCategory::class, 'tickets.category.manage');
    }

    public function storeCategory(TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->storeForModel($request, $tenantContext, TicketCategory::class, 'tickets.category.manage', $logger, 'tickets.category');
    }

    public function updateCategory(TicketCategory $item, TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->updateForModel($item, $request, $tenantContext, 'tickets.category.manage', $logger, 'tickets.category');
    }

    public function destroyCategory(TicketCategory $item, Request $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->destroyForModel($item, $request, $tenantContext, 'tickets.category.manage', $logger, 'tickets.category');
    }

    public function indexTags(Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        return $this->indexForModel($request, $tenantContext, TicketTag::class, 'tickets.tag.manage');
    }

    public function storeTag(TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->storeForModel($request, $tenantContext, TicketTag::class, 'tickets.tag.manage', $logger, 'tickets.tag');
    }

    public function updateTag(TicketTag $item, TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->updateForModel($item, $request, $tenantContext, 'tickets.tag.manage', $logger, 'tickets.tag');
    }

    public function destroyTag(TicketTag $item, Request $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->destroyForModel($item, $request, $tenantContext, 'tickets.tag.manage', $logger, 'tickets.tag');
    }

    public function indexQueues(Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        return $this->indexForModel($request, $tenantContext, TicketQueue::class, 'tickets.queue.manage');
    }

    public function storeQueue(TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->storeForModel($request, $tenantContext, TicketQueue::class, 'tickets.queue.manage', $logger, 'tickets.queue');
    }

    public function updateQueue(TicketQueue $item, TicketTaxonomyRequest $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->updateForModel($item, $request, $tenantContext, 'tickets.queue.manage', $logger, 'tickets.queue');
    }

    public function destroyQueue(TicketQueue $item, Request $request, TicketTenantContext $tenantContext, TicketActivityLogger $logger): JsonResponse
    {
        return $this->destroyForModel($item, $request, $tenantContext, 'tickets.queue.manage', $logger, 'tickets.queue');
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function indexForModel(Request $request, TicketTenantContext $tenantContext, string $modelClass, string $permission): JsonResponse
    {
        abort_unless(in_array($permission, $this->permissions($request), true), 403, 'Missing permission: '.$permission);
        $tenantId = $this->ticketTenantId($tenantContext);
        $rows = $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (Model $row): array => [
                'uuid' => (string) $row->getAttribute('uuid'),
                'name' => (string) $row->getAttribute('name'),
                'slug' => (string) $row->getAttribute('slug'),
                'description' => $row->getAttribute('description'),
                'is_active' => (bool) ($row->getAttribute('is_active') ?? true),
                'color' => $row->getAttribute('color'),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function storeForModel(
        TicketTaxonomyRequest $request,
        TicketTenantContext $tenantContext,
        string $modelClass,
        string $permission,
        TicketActivityLogger $logger,
        string $actionPrefix
    ): JsonResponse {
        abort_unless(in_array($permission, $this->permissions($request), true), 403, 'Missing permission: '.$permission);
        $tenantId = $this->ticketTenantId($tenantContext);
        $payload = $request->validated();

        $row = $modelClass::query()->create([
            'tenant_id' => $tenantId,
            'name' => (string) $payload['name'],
            'slug' => (string) $payload['slug'],
            'description' => $payload['description'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'color' => $payload['color'] ?? null,
        ]);

        $logger->log($this->authUser($request), $actionPrefix.'.created', ['uuid' => (string) $row->getAttribute('uuid')]);

        return response()->json([
            'data' => [
                'uuid' => (string) $row->getAttribute('uuid'),
                'name' => (string) $row->getAttribute('name'),
                'slug' => (string) $row->getAttribute('slug'),
                'description' => $row->getAttribute('description'),
                'is_active' => (bool) ($row->getAttribute('is_active') ?? true),
                'color' => $row->getAttribute('color'),
            ],
        ], 201);
    }

    private function updateForModel(
        Model $item,
        TicketTaxonomyRequest $request,
        TicketTenantContext $tenantContext,
        string $permission,
        TicketActivityLogger $logger,
        string $actionPrefix
    ): JsonResponse {
        abort_unless(in_array($permission, $this->permissions($request), true), 403, 'Missing permission: '.$permission);
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $item->getAttribute('tenant_id') === $tenantId, 404, 'Record not found');
        $payload = $request->validated();

        $item->fill([
            'name' => (string) $payload['name'],
            'slug' => (string) $payload['slug'],
            'description' => $payload['description'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'color' => $payload['color'] ?? null,
        ]);
        $item->save();

        $logger->log($this->authUser($request), $actionPrefix.'.updated', ['uuid' => (string) $item->getAttribute('uuid')]);

        return response()->json([
            'data' => [
                'uuid' => (string) $item->getAttribute('uuid'),
                'name' => (string) $item->getAttribute('name'),
                'slug' => (string) $item->getAttribute('slug'),
                'description' => $item->getAttribute('description'),
                'is_active' => (bool) ($item->getAttribute('is_active') ?? true),
                'color' => $item->getAttribute('color'),
            ],
        ]);
    }

    private function destroyForModel(
        Model $item,
        Request $request,
        TicketTenantContext $tenantContext,
        string $permission,
        TicketActivityLogger $logger,
        string $actionPrefix
    ): JsonResponse {
        abort_unless(in_array($permission, $this->permissions($request), true), 403, 'Missing permission: '.$permission);
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $item->getAttribute('tenant_id') === $tenantId, 404, 'Record not found');
        $uuid = (string) $item->getAttribute('uuid');
        $item->delete();
        $logger->log($this->authUser($request), $actionPrefix.'.deleted', ['uuid' => $uuid]);
        return response()->json(['status' => 'ok']);
    }
}
