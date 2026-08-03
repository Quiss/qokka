<?php

namespace App;

enum SourceType: string
{
    case Telegram = 'telegram';
    case JsonCollection = 'json_collection';
}
