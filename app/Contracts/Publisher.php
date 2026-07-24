<?php

namespace App\Contracts;

use App\Models\Delivery;
use App\Models\Destination;

interface Publisher
{
    /** @return array{ok: bool, details?: array<string, mixed>} */
    public function validateDestination(Destination $destination): array;

    /** @return array{message_ids: list<string>} */
    public function publish(Delivery $delivery): array;
}
