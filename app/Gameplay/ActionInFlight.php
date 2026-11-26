<?php

namespace App\Gameplay;

final class ActionInFlight extends GameException
{
    /**
     * @param  'ship'|'agent'  $scope
     * @param  list<string>  $ships
     * @param  list<int>  $actionIds
     * @param  'pending'|'uncertain_or_review'  $kind
     */
    public function __construct(
        string $message,
        int $retryAfter,
        public readonly string $scope,
        public readonly array $ships = [],
        public readonly array $actionIds = [],
        public readonly string $kind = 'pending',
    ) {
        parent::__construct($message, retryAfter: $retryAfter);
    }

    public function shipLocalPending(): bool
    {
        return $this->kind === 'pending' && $this->scope === 'ship';
    }
}
