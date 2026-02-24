<?php

namespace App\Services\Licensing;

use App\Models\ModuleEntitlement;
use App\Models\User;
use App\Models\UserModuleEntitlement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ModuleSeatService
{
    /**
     * @return Collection<int, array{module_slug:string,seats:int,assigned:int,available:int}>
     */
    public function seatOverview(): Collection
    {
        $effectiveSeats = $this->effectiveSeatCapsByModule();
        $assignedByModule = UserModuleEntitlement::query()
            ->selectRaw('module_slug, COUNT(*) as assigned_count')
            ->groupBy('module_slug')
            ->pluck('assigned_count', 'module_slug');

        return $effectiveSeats
            ->map(function (int $seats, string $moduleSlug) use ($assignedByModule): array {
                $assigned = (int) ($assignedByModule[$moduleSlug] ?? 0);
                $available = max(0, $seats - $assigned);

                return [
                    'module_slug' => $moduleSlug,
                    'seats' => $seats,
                    'assigned' => $assigned,
                    'available' => $available,
                ];
            })
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function assignedModuleSlugsForUser(User $user): array
    {
        return $user->moduleEntitlements()
            ->pluck('module_slug')
            ->map(static fn (string $slug): string => (string) $slug)
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $moduleSlugs
     */
    public function syncAssignmentsForUser(User $user, array $moduleSlugs, ?string $assignedByUuid): void
    {
        $normalized = collect($moduleSlugs)
            ->map(static fn (string $slug): string => trim($slug))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->unique()
            ->values();

        $seatCaps = $this->effectiveSeatCapsByModule();
        $invalid = $normalized->filter(static fn (string $slug): bool => !$seatCaps->has($slug))->values()->all();
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'assigned_module_slugs' => ['Module not licensed or inactive: '.implode(', ', $invalid)],
            ]);
        }

        foreach ($normalized as $moduleSlug) {
            $seatCap = (int) $seatCaps->get($moduleSlug, 0);
            $assignedCountExcludingUser = UserModuleEntitlement::query()
                ->where('module_slug', $moduleSlug)
                ->where('user_uuid', '!=', (string) $user->uuid)
                ->count();

            if ($assignedCountExcludingUser >= $seatCap) {
                throw ValidationException::withMessages([
                    'assigned_module_slugs' => [
                        sprintf(
                            'Seat limit reached for module %s (%d/%d).',
                            $moduleSlug,
                            $assignedCountExcludingUser,
                            $seatCap
                        ),
                    ],
                ]);
            }
        }

        $normalizedSet = $normalized->flip();
        $existingRows = $user->moduleEntitlements()->get();

        foreach ($existingRows as $row) {
            if (!$normalizedSet->has((string) $row->module_slug)) {
                $row->delete();
            }
        }

        foreach ($normalized as $moduleSlug) {
            UserModuleEntitlement::query()->updateOrCreate(
                [
                    'user_uuid' => (string) $user->uuid,
                    'module_slug' => $moduleSlug,
                ],
                [
                    'assigned_by_uuid' => $assignedByUuid,
                ]
            );
        }
    }

    public function userHasSeatForModule(User $user, string $moduleSlug): bool
    {
        return UserModuleEntitlement::query()
            ->where('user_uuid', (string) $user->uuid)
            ->where('module_slug', $moduleSlug)
            ->exists();
    }

    public function effectiveSeatCapForModule(string $moduleSlug): int
    {
        return (int) ($this->effectiveSeatCapsByModule()->get($moduleSlug) ?? 0);
    }

    /**
     * @return Collection<string, int>
     */
    private function effectiveSeatCapsByModule(): Collection
    {
        return ModuleEntitlement::query()
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->get(['module_slug', 'seats'])
            ->groupBy('module_slug')
            ->map(static function (Collection $rows): int {
                return (int) $rows->max(static fn (ModuleEntitlement $row): int => max(0, (int) $row->seats));
            });
    }
}

