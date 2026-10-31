<?php

namespace App\Enums;

enum ActionState: string
{
    case Preview = 'preview';
    case Pending = 'pending';
    case Sending = 'sending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';
    case Acknowledged = 'acknowledged';
}
