<?php

namespace App\Exceptions;

class InvalidStateException extends ApiException
{
    public function __construct(string $message = 'The resource is not in a valid state for this operation.', array $details = [])
    {
        parent::__construct('invalid_state', $message, 409, $details);
    }
}
