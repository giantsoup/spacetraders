<?php

namespace App\Gameplay;

/** Ends the entire local decision before previews, movement or fallback can consume its reserve. */
class LocalEarnBudget extends GameException
{
    public function __construct()
    {
        parent::__construct('Local Earn decision reserve reached.', retryAfter: 5);
    }
}
