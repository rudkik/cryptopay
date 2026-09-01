<?php

namespace App\Exceptions;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found.', array $details = [])
    {
        parent::__construct('not_found', $message, 404, $details);
    }
}
