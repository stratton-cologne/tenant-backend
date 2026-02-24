<?php

namespace App\Modules\Lifecycle;

use Closure;

class ModuleLifecycleContext
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly string $slug,
        public readonly array $manifest,
        private readonly Closure $lineWriter,
    ) {
    }

    public function line(string $message): void
    {
        ($this->lineWriter)($message);
    }
}

