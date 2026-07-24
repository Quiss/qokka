<?php

namespace App;

enum DestinationPlatform: string
{
    case Telegram = 'telegram';
    case Vk = 'vk';
    case Max = 'max';
}
