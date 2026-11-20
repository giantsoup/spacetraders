<?php

namespace App\Gameplay;

/** A known candidate-only refusal, classified where the candidate is evaluated. */
class LocalEarnRefusal extends GameException
{
    public function __construct(public readonly string $stage, string $message)
    {
        parent::__construct($message);
    }

    public static function planning(GameException $exception): GameException
    {
        $projection = self::projection($exception);
        if ($projection !== $exception || $exception->apiCode !== null || $exception->uncertain || $exception->retryAfter > 0) {
            return $projection;
        }

        return in_array($exception->getMessage(), [
            'This bounded cycle must return to a verified fuel seller at its starting waypoint.',
            'Round-trip travel or refueling exceeds the approved fuel/spending limits.',
            'Fuel economics changed since ranking. Rerank before creating a fresh campaign preview.',
            'Trade fails the complete-cycle margin, inventory exposure, cargo, spending or cash-loss bounds.',
            'Approved spending plus protected reserve exceeds available credits.',
        ], true) ? new self('plan', $exception->getMessage()) : $exception;
    }

    public static function projection(GameException $exception): GameException
    {
        if ($exception->apiCode !== null || $exception->uncertain || $exception->retryAfter > 0) {
            return $exception;
        }

        return in_array($exception->getMessage(), [
            'No profitable lot fits cargo, fuel, proof and duration bounds.',
            'Discover a same-system route before planning travel.',
            'No fuel-safe continuation to a verified affordable fuel seller. Refresh prices, refuel or replan before departure.',
            'Measured fuel evidence for this configuration and route is missing.',
        ], true) ? new self('projection', $exception->getMessage()) : $exception;
    }
}
