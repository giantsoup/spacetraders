<?php

namespace App\Gameplay;

final class RequestDeferred extends GameException
{
    public function __construct(
        string $message,
        int $seconds,
        public readonly ?string $delaySource = null,
        public readonly bool $deadlineCapped = false,
        public readonly array $context = [],
    ) {
        parent::__construct($message, retryAfter: $seconds);
    }

    public function withDeadline(?float $deadline): self
    {
        if ($deadline === null || ! is_finite($deadline) || array_key_exists('remaining_deadline_seconds', $this->context)) {
            return $this;
        }

        return new self($this->getMessage(), $this->retryAfter, $this->delaySource, $this->deadlineCapped,
            $this->context + ['remaining_deadline_seconds' => max(0.0, $deadline - microtime(true))]);
    }
}
