<?php

namespace App\Gameplay\Engine;

/** SQLite lock contention outlasted the supervisor's heartbeat allowance. */
final class HeartbeatContention extends \RuntimeException {}
