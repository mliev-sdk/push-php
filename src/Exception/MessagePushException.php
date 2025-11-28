<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Exception;

use Exception;
use Throwable;

/**
 * Base exception for Message Push SDK
 */
class MessagePushException extends Exception
{
    protected int $errorCode;
    protected ?array $responseData;

    public function __construct(
        string $message = '',
        int $errorCode = 0,
        ?array $responseData = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $previous);
        $this->errorCode = $errorCode;
        $this->responseData = $responseData;
    }

    /**
     * Get the API error code
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * Get the full response data
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}

