<?php

namespace App\Modules;

use App\Modules\Contracts\ModuleLifecycle;
use App\Modules\Lifecycle\ModuleLifecycleContext;
use App\Modules\Lifecycle\NullModuleLifecycle;
use RuntimeException;

class ModuleLifecycleRunner
{
    /**
     * @param array<string, mixed> $module
     */
    public function install(array $module, ModuleLifecycleContext $context): void
    {
        $this->resolveLifecycle($module)->install($context);
    }

    /**
     * @param array<string, mixed> $module
     */
    public function enable(array $module, ModuleLifecycleContext $context): void
    {
        $this->resolveLifecycle($module)->enable($context);
    }

    /**
     * @param array<string, mixed> $module
     */
    public function disable(array $module, ModuleLifecycleContext $context): void
    {
        $this->resolveLifecycle($module)->disable($context);
    }

    /**
     * @param array<string, mixed> $module
     */
    public function uninstall(array $module, ModuleLifecycleContext $context): void
    {
        $this->resolveLifecycle($module)->uninstall($context);
    }

    /**
     * @param array<string, mixed> $module
     */
    public function upgrade(array $module, ModuleLifecycleContext $context, ?string $fromVersion, string $toVersion): void
    {
        $this->resolveLifecycle($module)->upgrade($context, $fromVersion, $toVersion);
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolveLifecycle(array $module): ModuleLifecycle
    {
        $lifecycle = (array) ($module['lifecycle'] ?? []);
        $class = trim((string) ($lifecycle['class'] ?? ''));
        $file = trim((string) ($lifecycle['file'] ?? ''));

        if ($file !== '') {
            if (!is_file($file)) {
                throw new RuntimeException('Lifecycle file not found: '.$file);
            }
            require_once $file;
        }

        if ($class === '') {
            return new NullModuleLifecycle();
        }

        if (!class_exists($class)) {
            throw new RuntimeException('Lifecycle class not found: '.$class);
        }

        $instance = app($class);
        if (!$instance instanceof ModuleLifecycle) {
            throw new RuntimeException('Lifecycle class must implement '.ModuleLifecycle::class.': '.$class);
        }

        return $instance;
    }
}

