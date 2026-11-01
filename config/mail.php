<?php

return [
    // This private client never delivers or logs mail, even with a stale MAIL_MAILER.
    'default' => 'array',
    'mailers' => [
        'array' => ['transport' => 'array'],
    ],
    'from' => [
        'address' => 'operator@example.test',
        'name' => 'SpaceTraders',
    ],
];
