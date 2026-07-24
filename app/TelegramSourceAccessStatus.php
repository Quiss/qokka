<?php

namespace App;

enum TelegramSourceAccessStatus: string
{
    case Unknown = 'unknown';
    case Available = 'available';
    case Unavailable = 'unavailable';
}
