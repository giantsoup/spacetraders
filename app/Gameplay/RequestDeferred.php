<?php

namespace App\Gameplay;

final class RequestDeferred extends GameException
{
    public function __construct(string $message, int $seconds)
    {
        parent::__construct($message, retryAfter: $seconds);
    }
}
