<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Response;

/**
 * API Response wrapper
 */
class Response
{
    private int $code;
    private string $message;
    private ?array $data;
    private array $rawResponse;

    public function __construct(array $response)
    {
        $this->rawResponse = $response;
        $this->code = (int) ($response['code'] ?? -1);
        $this->message = (string) ($response['message'] ?? '');
        $this->data = $response['data'] ?? null;
    }

    /**
     * Check if the request was successful
     */
    public function isSuccess(): bool
    {
        return $this->code === 0;
    }

    /**
     * Get the response code
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Get the response message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the response data
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * Get the raw response array
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Get task ID from response (for send message)
     */
    public function getTaskId(): ?string
    {
        return $this->data['task_id'] ?? null;
    }

    /**
     * Get batch ID from response (for batch send)
     */
    public function getBatchId(): ?string
    {
        return $this->data['batch_id'] ?? null;
    }

    /**
     * Get status from response
     */
    public function getStatus(): ?string
    {
        return $this->data['status'] ?? null;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return $this->rawResponse;
    }
}

