<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Enums;

enum OutboxStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
}
