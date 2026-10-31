<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Events;

use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class OutboxMessageProcessed
{
    use Dispatchable;

    public function __construct(public OutboxMessage $message)
    {
    }
}