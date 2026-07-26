<?php

namespace App\Contracts;

use App\OperationsNotificationTopic;

interface OperationsNotifier
{
    /** @param list<string> $details */
    public function send(
        OperationsNotificationTopic $topic,
        string $title,
        array $details,
        string $url,
    ): void;
}
