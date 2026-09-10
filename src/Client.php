<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP;

use InvalidArgumentException;
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
     * @param string|null $signatureName  Alias from signature_names; required when signature_required is true
     * @param string|null $scheduledAt    Scheduled time in ISO 8601 format (optional)
     * @param array       $attachments    EmailAttachment objects or attachment wire-format arrays
     *
     * @return Response
     * @throws MessagePushException
     * @throws InvalidArgumentException
     */
    public function sendMessage(
        int $channelId,
        string $receiver,
        array $templateParams = [],
        ?string $signatureName = null,
        ?string $scheduledAt = null,
        array $attachments = []
    ): Response {
        $data = [
            'channel_id' => $channelId,
            'receiver' => $receiver,
            'template_params' => $templateParams === [] ? (object) [] : $templateParams,
        ];

        if ($signatureName !== null) {
            $data['signature_name'] = $signatureName;
        }

        if ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }

        if ($attachments !== []) {
            $data['attachments'] = $this->normalizeAttachments($attachments);
        }

        return $this->request('POST', '/api/v1/messages', $data);
    }

    /**
     * Send batch messages
     *
     * @param int         $channelId      Channel ID
     * @param array       $receivers      Array of receivers
     * @param array       $templateParams Template parameters (shared by all receivers)
     * @param string|null $signatureName  Alias from signature_names; required when signature_required is true
     * @param string|null $scheduledAt    Scheduled time in ISO 8601 format (optional)
     * @param array       $attachments    EmailAttachment objects or attachment wire-format arrays shared by all receivers
     *
     * @return Response
     * @throws MessagePushException
     * @throws InvalidArgumentException
     */
    public function sendBatch(
        int $channelId,
        array $receivers,
        array $templateParams = [],
        ?string $signatureName = null,
        ?string $scheduledAt = null,
        array $attachments = []
    ): Response {
        $data = [
            'channel_id' => $channelId,
            'receivers' => $receivers,
            'template_params' => $templateParams === [] ? (object) [] : $templateParams,
        ];

        if ($signatureName !== null) {
            $data['signature_name'] = $signatureName;
        }

        if ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }

        if ($attachments !== []) {
            $data['attachments'] = $this->normalizeAttachments($attachments);
        }

        return $this->request('POST', '/api/v1/messages/batch', $data);
    }

    /**
     * Normalize and validate attachment objects and raw wire-format arrays.
     *
     * @param array $attachments
     * @return array
     */
    private function normalizeAttachments(array $attachments): array
    {
        $normalized = [];
        foreach ($attachments as $index => $attachment) {
            if ($attachment instanceof EmailAttachment) {
                $normalized[] = $attachment->toArray();
                continue;
            }
            if (!is_array($attachment)) {
                throw new InvalidArgumentException(sprintf(
                    'Attachment %s must be an EmailAttachment or array',
                    (string) $index
                ));
            }
            if (!isset($attachment['filename']) || !is_string($attachment['filename'])) {
                throw new InvalidArgumentException(sprintf('Attachment %s filename must be a string', (string) $index));
            }
            if (!isset($attachment['content_base64']) || !is_string($attachment['content_base64'])) {
                throw new InvalidArgumentException(sprintf('Attachment %s content_base64 must be a string', (string) $index));
            }
            $contentType = $attachment['content_type'] ?? null;
            if ($contentType !== null && !is_string($contentType)) {
                throw new InvalidArgumentException(sprintf('Attachment %s content_type must be a string', (string) $index));
            }
            $normalized[] = EmailAttachment::fromBase64(
                $attachment['filename'],
                $attachment['content_base64'],
                $contentType
            )->toArray();
        }

        return $normalized;
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
     * List enabled channels, including blocked ones, without consuming sending quota.
     *
     * getData() contains items, total, page and size. Each item contains id, name,
     * type, message_template_id, template_name and readiness (state, blocker_codes).
     * Pagination and type are validated by the server; page_size is limited to 100.
     *
     * @param string|null $type     Message type filter; null or empty means all types
     * @param int         $page     Page number, starting at 1
     * @param int         $pageSize Items per page, default 20
     * @return Response
     * @throws MessagePushException
     */
    public function listChannels(?string $type = null, int $page = 1, int $pageSize = 20): Response
    {
        $query = ['page' => $page, 'page_size' => $pageSize];
        if ($type !== null && $type !== '') {
            $query['type'] = $type;
        }

        return $this->request('GET', '/api/v1/channels', null, $query);
    }

    /**
     * Get channel configuration. A blocked channel is still a successful query.
     *
     * getData() contains the channel list fields plus template, signature_required
     * and signature_names. template is null when missing; otherwise it contains id,
     * template_name, content_type, content, variables and description. variables is
     * null for invalid configuration, [] for no variables, or an array of strings.
     * Pass the selected signature_names alias unchanged to sendMessage/sendBatch.
     *
     * @param int $channelId Channel ID from listChannels()
     * @return Response
     * @throws MessagePushException
     */
    public function getChannel(int $channelId): Response
    {
        return $this->request('GET', '/api/v1/channels/' . $channelId);
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
     * @param array      $query  URL query parameters, excluded from the signature
     *
     * @return Response
     * @throws MessagePushException
     */
    private function request(string $method, string $path, ?array $data = null, array $query = []): Response
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->generateSignature($method, $path, $data, $timestamp, $nonce);

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
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
