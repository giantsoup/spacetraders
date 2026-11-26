<?php

namespace App\Gameplay;

final class IntentExpiredBeforeTransport extends GameException
{
    public function __construct(string $message, public readonly string $stage, public readonly ?string $deferSource = null)
    {
        parent::__construct($message);
    }
}
