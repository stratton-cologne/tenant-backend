<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Models\ModuleEntitlement;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserModuleEntitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardWidgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $rows = DashboardWidget::query()
            ->where('user_id', $user->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (DashboardWidget $widget) use ($user): bool {
                $allowed = $this->allowedWidgetKeys();
                if ($allowed !== null && !in_array((string) $widget->widget_key, $allowed, true)) {
                    return false;
                }

                return $this->isWidgetAllowedForUser($user, (string) $widget->widget_key);
            })
            ->map(fn (DashboardWidget $widget): array => [
                'widget_key' => (string) $widget->widget_key,
                'x' => (int) $widget->x,
                'y' => (int) $widget->y,
                'w' => (int) $widget->w,
                'h' => (int) $widget->h,
                'sort_order' => (int) $widget->sort_order,
                'enabled' => (bool) $widget->enabled,
                'config' => is_array($widget->config_json) ? $widget->config_json : [],
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'widgets' => $rows,
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = $request->validate([
            'widgets' => ['required', 'array', 'max:100'],
            'widgets.*.widget_key' => ['required', 'string', 'max:120'],
            'widgets.*.x' => ['required', 'integer', 'min:0', 'max:12'],
            'widgets.*.y' => ['required', 'integer', 'min:0', 'max:1000'],
            'widgets.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'widgets.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'widgets.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'widgets.*.enabled' => ['required', 'boolean'],
            'widgets.*.config' => ['nullable', 'array'],
        ]);

        $widgets = (array) ($payload['widgets'] ?? []);
        $allowed = $this->allowedWidgetKeys();
        if ($allowed !== null) {
            $widgets = array_values(array_filter($widgets, function (mixed $entry) use ($allowed, $user): bool {
                if (!is_array($entry)) {
                    return false;
                }

                $widgetKey = (string) ($entry['widget_key'] ?? '');
                if (!in_array($widgetKey, $allowed, true)) {
                    return false;
                }

                return $this->isWidgetAllowedForUser($user, $widgetKey);
            }));
        } else {
            $widgets = array_values(array_filter($widgets, function (mixed $entry) use ($user): bool {
                if (!is_array($entry)) {
                    return false;
                }

                return $this->isWidgetAllowedForUser($user, (string) ($entry['widget_key'] ?? ''));
            }));
        }

        DB::transaction(function () use ($user, $widgets): void {
            foreach ($widgets as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                DashboardWidget::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'widget_key' => (string) $entry['widget_key'],
                    ],
                    [
                        'x' => (int) $entry['x'],
                        'y' => (int) $entry['y'],
                        'w' => (int) $entry['w'],
                        'h' => (int) $entry['h'],
                        'sort_order' => (int) ($entry['sort_order'] ?? $index),
                        'enabled' => (bool) $entry['enabled'],
                        'config_json' => isset($entry['config']) && is_array($entry['config']) ? $entry['config'] : [],
                    ]
                );
            }

            $keys = collect($widgets)
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(fn (array $row): string => (string) ($row['widget_key'] ?? ''))
                ->filter(fn (string $key): bool => $key !== '')
                ->values()
                ->all();

            if ($keys === []) {
                DashboardWidget::query()->where('user_id', $user->id)->delete();
                return;
            }

            DashboardWidget::query()
                ->where('user_id', $user->id)
                ->whereNotIn('widget_key', $keys)
                ->delete();
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedWidgetKeys(): ?array
    {
        $setting = TenantSetting::query()->where('key', 'dashboard_allowed_widgets')->first();
        if ($setting === null) {
            return null;
        }

        $raw = $setting->value_json;
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $raw),
            static fn (string $entry): bool => $entry !== ''
        )));
    }

    private function isWidgetAllowedForUser(User $user, string $widgetKey): bool
    {
        $widgetKey = trim($widgetKey);
        if ($widgetKey === '') {
            return false;
        }

        $moduleSlug = Str::before($widgetKey, '.');
        if ($moduleSlug === '' || $moduleSlug === 'system') {
            return true;
        }

        $moduleIsActiveForTenant = ModuleEntitlement::query()
            ->where('module_slug', $moduleSlug)
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->exists();

        if (!$moduleIsActiveForTenant) {
            return false;
        }

        return UserModuleEntitlement::query()
            ->where('user_uuid', (string) $user->uuid)
            ->where('module_slug', $moduleSlug)
            ->exists();
    }
}
