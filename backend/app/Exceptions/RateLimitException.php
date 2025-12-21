<?php

namespace App\Exceptions;

use Exception;

class RateLimitException extends Exception
{
    protected $waitSeconds;
    
    public function __construct($message = "Rate limit exceeded", $waitSeconds = 60, $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->waitSeconds = $waitSeconds;
    }
    
    public function getWaitSeconds()
    {
        return $this->waitSeconds;
    }
}

