<?php

namespace App\Exceptions;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'This action is forbidden.', array $details = [])
    {
        parent::__construct('forbidden', $message, 403, $details);
    }
}
