<?php

namespace App;

enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Animation = 'animation';
    case Document = 'document';

    /** @return list<self> */
    public static function publishableCases(): array
    {
        return [
            self::Photo,
            self::Video,
            self::Animation,
        ];
    }
}
