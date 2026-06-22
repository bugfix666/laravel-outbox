<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('outbox.table'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('available_at')->nullable();

            $table->index(['processed_at', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('available_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('outbox.table'));
    }
};