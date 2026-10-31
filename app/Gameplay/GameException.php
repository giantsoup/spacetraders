<?php

namespace App\Gameplay;

use RuntimeException;

class GameException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $apiCode = null, public readonly bool $uncertain = false, public readonly int $retryAfter = 0) // seconds
    {
        parent::__construct($message);
    }
}
