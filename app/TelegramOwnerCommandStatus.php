<?php

namespace App;

enum TelegramOwnerCommandStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
