<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (!is_string($model->getAttribute('uuid')) || trim((string) $model->getAttribute('uuid')) === '') {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}
