<?php

namespace App\Queue;

use App\Gameplay\DeploymentGate;
use Illuminate\Queue\DatabaseQueue;

class DeploymentDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        if ($this->getQueue($queue) !== 'spacetraders') {
            return parent::pop($queue);
        }

        return app(DeploymentGate::class)->reserve(fn () => parent::pop($queue));
    }
}
