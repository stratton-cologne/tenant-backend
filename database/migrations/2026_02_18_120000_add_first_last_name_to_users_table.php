<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('id');
            }

            if (!Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
        });

        if (!Schema::hasColumn('users', 'name')) {
            return;
        }

        DB::table('users')
            ->select('id', 'name', 'first_name', 'last_name')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $hasFirstName = is_string($row->first_name) && trim($row->first_name) !== '';
                    $hasLastName = is_string($row->last_name) && trim($row->last_name) !== '';
                    if ($hasFirstName && $hasLastName) {
                        continue;
                    }

                    $fullName = trim((string) ($row->name ?? ''));
                    if ($fullName === '') {
                        $firstName = 'User';
                        $lastName = (string) $row->id;
                    } else {
                        $parts = preg_split('/\s+/', $fullName) ?: [];
                        $firstName = (string) ($parts[0] ?? 'User');
                        $lastName = trim(implode(' ', array_slice($parts, 1)));
                        if ($lastName === '') {
                            $lastName = 'User';
                        }
                    }

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'first_name' => $hasFirstName ? $row->first_name : $firstName,
                            'last_name' => $hasLastName ? $row->last_name : $lastName,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // noop: columns may come from the base users migration in fresh environments.
    }
};
