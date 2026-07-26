<?php

namespace App;

enum OperationsNotificationTopic: string
{
    case ContentPlans = 'content-plans';
    case Failures = 'failures';

    public function configKey(): string
    {
        return match ($this) {
            self::ContentPlans => 'content_plans',
            self::Failures => 'failures',
        };
    }
}
