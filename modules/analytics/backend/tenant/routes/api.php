<?php

use Illuminate\Support\Facades\Route;

Route::prefix('tenant/modules/analytics')
    ->middleware(['auth.token', 'entitled:analytics', 'permission:analytics.view'])
    ->group(function (): void {
        Route::get('/health', static function (): array {
            return [
                'module' => 'analytics',
                'status' => 'ok',
                'timestamp' => now()->toISOString(),
            ];
        });
    });
