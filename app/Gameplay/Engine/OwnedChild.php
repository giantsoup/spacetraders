<?php

namespace App\Gameplay\Engine;

/** A child process started and owned by the current supervisor. */
interface OwnedChild
{
    public function pid(): ?int;

    public function isRunning(): bool;

    /** Wait up to $seconds for the child to be running and registered. */
    public function ready(float $seconds): bool;

    /** Ask the child to finish its current work and exit. */
    public function requestStop(): void;

    /** Terminate the child and its descendants immediately. */
    public function kill(): void;
}
