<?php

namespace App\Enums;

enum RunState: string
{
    case Preview = 'preview';
    case Running = 'running';
    case Waiting = 'waiting';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Failed = 'failed';
}
