<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP;

use MlievSdk\PushPHP\Exception\MessagePushException;
use MlievSdk\PushPHP\Exception\RequestException;
use MlievSdk\PushPHP\Response\Response;

/**
 * Message Push SDK Client
 */
class Client
{
    private string $baseUrl;
    private string $appId;
    private string $appSecret;
    private int $timeout;

    /**
     * Create a new client instance
     *
     * @param string $baseUrl   API base URL (e.g., https://your-domain.com)
     * @param string $appId     Application ID
     * @param string $appSecret Application secret
     * @param int    $timeout   Request timeout in seconds (default: 10)
     */
    public function __construct(
        string $baseUrl,
        string $appId,
        string $appSecret,
        int $timeout = 10
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->timeout = $timeout;
    }

    /**
     * Send a single message
     *
     * @param int         $channelId      Channel ID
     * @param string      $receiver       Receiver (phone/email/user ID)
     * @param array       $templateParams Template parameters
     * @param string|null $signatureName  SMS signature name (optional)
     * @param string|null $scheduledAt    Scheduled time in ISO 8601 format (optional)
     *
     * @return Response
     * @throws MessagePushException
     */
    public function sendMessage(
        int $channelId,
        string $receiver,
        array $templateParams = [],
        ?string $signatureName = null,
        ?string $scheduledAt = null
    ): Response {
        $data = [
            'channel_id' => $channelId,
            'receiver' => $receiver,
            'template_params' => $templateParams,
        ];

        if ($signatureName !== null) {
            $data['signature_name'] = $signatureName;
        }

        if ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }

        return $this->request('POST', '/api/v1/messages', $data);
    }

    /**
     * Send batch messages
     *
     * @param int         $channelId      Channel ID
     * @param array       $receivers      Array of receivers
     * @param array       $templateParams Template parameters (shared by all receivers)
     * @param string|null $signatureName  SMS signature name (optional)
     * @param string|null $scheduledAt    Scheduled time in ISO 8601 format (optional)
     *
     * @return Response
     * @throws MessagePushException
     */
    public function sendBatch(
        int $channelId,
        array $receivers,
        array $templateParams = [],
        ?string $signatureName = null,
        ?string $scheduledAt = null
    ): Response {
        $data = [
            'channel_id' => $channelId,
            'receivers' => $receivers,
            'template_params' => $templateParams,
        ];

        if ($signatureName !== null) {
            $data['signature_name'] = $signatureName;
        }

        if ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }

        return $this->request('POST', '/api/v1/messages/batch', $data);
    }

    /**
     * Query task status
     *
     * @param string $taskId Task ID (UUID)
     *
     * @return Response
     * @throws MessagePushException
     */
    public function queryTask(string $taskId): Response
    {
        return $this->request('GET', '/api/v1/messages/' . $taskId);
    }

    /**
     * Sort parameters by key recursively
     *
     * @param array|null $params Parameters to sort
     *
     * @return string JSON string of sorted parameters
     */
    private function sortParams(?array $params): string
    {
        if ($params === null || empty($params)) {
            return '';
        }

        $sorted = $this->recursiveKeySort($params);
        return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recursively sort array by keys
     *
     * @param array $array Array to sort
     *
     * @return array Sorted array
     */
    private function recursiveKeySort(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->recursiveKeySort($value);
            }
        }
        return $array;
    }

    /**
     * Generate request signature
     *
     * @param string     $method    HTTP method
     * @param string     $path      Request path
     * @param array|null $params    Request parameters
     * @param string     $timestamp Unix timestamp
     * @param string     $nonce     Random string
     *
     * @return string Signature
     */
    private function generateSignature(
        string $method,
        string $path,
        ?array $params,
        string $timestamp,
        string $nonce
    ): string {
        $sortedParams = $this->sortParams($params);

        // Construct sign content: method + path + sorted_params + timestamp + nonce
        $signContent = $method . $path . $sortedParams . $timestamp . $nonce;

        // HMAC-SHA256 and hex encode
        return hash_hmac('sha256', $signContent, $this->appSecret);
    }

    /**
     * Send HTTP request
     *
     * @param string     $method HTTP method
     * @param string     $path   Request path
     * @param array|null $data   Request data
     *
     * @return Response
     * @throws MessagePushException
     */
    private function request(string $method, string $path, ?array $data = null): Response
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->generateSignature($method, $path, $data, $timestamp, $nonce);

        $url = $this->baseUrl . $path;
        $body = $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $headers = [
            'Content-Type: application/json',
            'X-App-Id: ' . $this->appId,
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RequestException(
                'cURL error: ' . $error,
                $errno
            );
        }

        if ($response === false) {
            throw new RequestException(
                'Failed to get response from server',
                $httpCode
            );
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestException(
                'Invalid JSON response: ' . json_last_error_msg(),
                $httpCode,
                ['raw_response' => $response]
            );
        }

        $responseObj = new Response($decoded);

        if (!$responseObj->isSuccess()) {
            throw new MessagePushException(
                $responseObj->getMessage(),
                $responseObj->getCode(),
                $decoded
            );
        }

        return $responseObj;
    }
}

