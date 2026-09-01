<?php

namespace App\Exceptions;

class WatcherUnavailableException extends ApiException
{
    public function __construct(string $message = 'The address watcher service is unreachable, a deposit address cannot be allocated right now.', array $details = [])
    {
        parent::__construct('watcher_unavailable', $message, 503, $details);
    }
}
