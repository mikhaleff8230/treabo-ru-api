<?php

namespace App\Services\AiAssistant;

class RequestDraftException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly bool $retryable = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
