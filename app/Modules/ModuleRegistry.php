<?php

namespace App\Modules;

use Illuminate\Support\Facades\File;

class ModuleRegistry
{
    public function __construct(private readonly ?string $modulesBasePath = null)
    {
    }

    /**
     * @return array<string, array{
     *   slug:string,
     *   name:string,
     *   version:string,
     *   description:string,
     *   module_dir:string,
     *   permissions:array<int, string>,
     *   lifecycle:array{
     *     file:string,
     *     class:string
     *   },
     *   tenant_backend:array{
     *     routes:array{api:array<int, string>},
     *     migrations:array<int, string>
     *   }
     * }>
     */
    public function discover(): array
    {
        $basePath = $this->resolveBasePath();
        if (!is_dir($basePath)) {
            return [];
        }

        $modules = [];
        foreach (File::directories($basePath) as $moduleDir) {
            $manifestPath = rtrim($moduleDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifestRaw = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifestRaw)) {
                continue;
            }

            $manifest = $this->normalizeManifest($manifestRaw, $moduleDir);
            $modules[$manifest['slug']] = $manifest;
        }

        ksort($modules);

        return $modules;
    }

    /**
     * @return array{
     *   slug:string,
     *   name:string,
     *   version:string,
     *   description:string,
     *   module_dir:string,
     *   permissions:array<int, string>,
     *   lifecycle:array{
     *     file:string,
     *     class:string
     *   },
     *   tenant_backend:array{
     *     routes:array{api:array<int, string>},
     *     migrations:array<int, string>
     *   }
     * }|null
     */
    public function find(string $slug): ?array
    {
        return $this->discover()[trim($slug)] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function collectApiRouteFiles(?string $slug = null): array
    {
        $modules = $slug === null ? $this->discover() : array_filter([$this->find($slug)]);
        $paths = [];

        foreach ($modules as $module) {
            foreach ($module['tenant_backend']['routes']['api'] as $routePath) {
                if (is_file($routePath)) {
                    $paths[] = $routePath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, string>
     */
    public function collectTenantMigrationPaths(?string $slug = null): array
    {
        $modules = $slug === null ? $this->discover() : array_filter([$this->find($slug)]);
        $paths = [];

        foreach ($modules as $module) {
            foreach ($module['tenant_backend']['migrations'] as $migrationPath) {
                if (is_dir($migrationPath)) {
                    $paths[] = $migrationPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function resolveBasePath(): string
    {
        return $this->modulesBasePath !== null
            ? rtrim($this->modulesBasePath, DIRECTORY_SEPARATOR)
            : base_path('modules');
    }

    /**
     * @param array<string, mixed> $manifestRaw
     * @return array{
     *   slug:string,
     *   name:string,
     *   version:string,
     *   description:string,
     *   module_dir:string,
     *   permissions:array<int, string>,
     *   lifecycle:array{
     *     file:string,
     *     class:string
     *   },
     *   tenant_backend:array{
     *     routes:array{api:array<int, string>},
     *     migrations:array<int, string>
     *   }
     * }
     */
    private function normalizeManifest(array $manifestRaw, string $moduleDir): array
    {
        $slug = trim((string) ($manifestRaw['slug'] ?? basename($moduleDir)));
        $name = trim((string) ($manifestRaw['name'] ?? ucfirst($slug)));
        $version = trim((string) ($manifestRaw['version'] ?? '0.0.0'));
        $description = trim((string) ($manifestRaw['description'] ?? ''));
        $lifecycleRaw = (array) ($manifestRaw['lifecycle'] ?? []);
        $lifecycleFile = $this->toAbsolutePath($moduleDir, (string) ($lifecycleRaw['file'] ?? ''));
        $lifecycleClass = trim((string) ($lifecycleRaw['class'] ?? ''));

        $permissions = array_values(array_filter(
            array_map(static fn (mixed $permission): string => trim((string) $permission), (array) ($manifestRaw['permissions'] ?? [])),
            static fn (string $permission): bool => $permission !== ''
        ));

        $tenantBackend = (array) ($manifestRaw['tenant_backend'] ?? []);
        $routes = (array) ($tenantBackend['routes'] ?? []);
        $apiRoutes = array_values(array_filter(
            array_map(fn (mixed $path): string => $this->toAbsolutePath($moduleDir, (string) $path), (array) ($routes['api'] ?? [])),
            static fn (string $path): bool => $path !== ''
        ));

        $migrations = array_values(array_filter(
            array_map(fn (mixed $path): string => $this->toAbsolutePath($moduleDir, (string) $path), (array) ($tenantBackend['migrations'] ?? [])),
            static fn (string $path): bool => $path !== ''
        ));

        return [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'module_dir' => $moduleDir,
            'permissions' => array_values(array_unique($permissions)),
            'lifecycle' => [
                'file' => $lifecycleFile,
                'class' => $lifecycleClass,
            ],
            'tenant_backend' => [
                'routes' => ['api' => $apiRoutes],
                'migrations' => $migrations,
            ],
        ];
    }

    private function toAbsolutePath(string $moduleDir, string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return rtrim($moduleDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($trimmed, DIRECTORY_SEPARATOR);
    }
}
