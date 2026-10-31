<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('outbox.connection'));
        $tableName = config('outbox.table', 'outbox_messages');

        $missingColumns = array_values(array_filter(
            ['failed_at', 'last_error', 'locked_at', 'lock_token'],
            fn (string $column): bool => !$schema->hasColumn($tableName, $column),
        ));

        if ($missingColumns !== []) {
            $schema->table($tableName, function (Blueprint $table) use ($missingColumns): void {
                foreach ($missingColumns as $column) {
                    match ($column) {
                        'failed_at' => $table->timestamp('failed_at')->nullable(),
                        'last_error' => $table->text('last_error')->nullable(),
                        'locked_at' => $table->timestamp('locked_at')->nullable(),
                        'lock_token' => $table->uuid('lock_token')->nullable(),
                    };
                }
            });
        }

        $schema->table($tableName, function (Blueprint $table): void {
            $table->index(['failed_at', 'available_at'], 'outbox_failed_available_index');
            $table->index('locked_at', 'outbox_locked_at_index');
        });

        // Older versions could leave messages at max_attempts without an
        // explicit failed_at timestamp. Promote those records to the DLQ state.
        DB::connection(config('outbox.connection'))
            ->table($tableName)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('attempts', '>=', max(1, (int) config('outbox.retry.max_attempts', 5)))
            ->update(['failed_at' => now(), 'available_at' => null]);
    }

    public function down(): void
    {
        $schema = Schema::connection(config('outbox.connection'));
        $tableName = config('outbox.table', 'outbox_messages');

        if (!$schema->hasTable($tableName)) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table) use ($schema, $tableName): void {
            $table->dropIndex('outbox_failed_available_index');
            $table->dropIndex('outbox_locked_at_index');

            $existingColumns = array_values(array_filter(
                ['failed_at', 'last_error', 'locked_at', 'lock_token'],
                fn (string $column): bool => $schema->hasColumn($tableName, $column),
            ));

            if ($existingColumns !== []) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};
