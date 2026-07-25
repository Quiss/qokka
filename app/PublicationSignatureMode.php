<?php

namespace App;

enum PublicationSignatureMode: string
{
    case Username = 'username';
    case Link = 'link';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Username => '@username',
            self::Link => 'Кликабельное название',
            self::None => 'Без подписи',
        };
    }
}
