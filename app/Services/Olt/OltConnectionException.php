<?php

namespace App\Services\Olt;

use RuntimeException;

class OltConnectionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'connection_failed')
    {
        parent::__construct($message);
    }
}
