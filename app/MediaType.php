<?php

namespace App;

enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Animation = 'animation';
    case Document = 'document';
}
