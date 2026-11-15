<?php

namespace App\Gameplay;

class CampaignRegistrationFailure extends GameException
{
    public function __construct(public readonly string $outcome)
    {
        parent::__construct('Campaign registration '.$outcome.'.', retryAfter: 60);
    }
}
