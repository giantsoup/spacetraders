<?php

namespace App\Gameplay;

final class DirectorAgentLockTimeout extends GameException
{
    public function __construct()
    {
        parent::__construct('Campaign agent command boundary remained busy until the acquisition deadline. Retry shortly.', retryAfter: 2);
    }
}
