<?php

namespace Bugfix666\LaravelOutbox\Enums;

enum OutboxStatus: string
{
    case PENDING = 'pending';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
}