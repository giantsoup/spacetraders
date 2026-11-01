<?php

namespace App\Gameplay;

use Carbon\CarbonImmutable;

final readonly class AutomationStep
{
    private function __construct(public string $kind, public ?string $command = null, public array $arguments = [], public ?CarbonImmutable $until = null, public ?string $reason = null) {}

    public static function act(string $command, array $arguments = []): self
    {
        return new self('act', $command, $arguments);
    }

    public static function wait(CarbonImmutable $until): self
    {
        return new self('wait', until: $until);
    }

    public static function blocked(string $reason): self
    {
        return new self('blocked', reason: $reason);
    }

    public static function trip(): self
    {
        return new self('trip');
    }
}
