<?php

namespace App\Gameplay\Engine;

final class HostResult
{
    public function __construct(public readonly int $exit, public readonly string $out = '', public readonly string $err = '') {}

    public function successful(): bool
    {
        return $this->exit === 0;
    }
}
