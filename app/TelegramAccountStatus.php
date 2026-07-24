<?php

namespace App;

enum TelegramAccountStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Connected = 'connected';
    case Error = 'error';
}
