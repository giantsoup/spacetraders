<?php

namespace App\Gameplay;

class ReadStateUnavailable extends GameException
{
    public function __construct(string $message)
    {
        parent::__construct($message, retryAfter: 5);
    }
}
