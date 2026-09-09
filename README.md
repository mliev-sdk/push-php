# Mliev Message Push PHP SDK

[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

PHP SDK for Mliev Message Push Service. Supports SMS, Email, WeChatWork, DingTalk, and Webhook messaging.

[中文文档](README.zh-cn.md)

## Requirements

- PHP 7.4+
- cURL extension
- JSON extension

## Installation

```bash
composer require mliev-sdk/push-php
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use MlievSdk\PushPHP\Client;
use MlievSdk\PushPHP\Exception\MessagePushException;

$client = new Client(
    'https://your-domain.com',  // API base URL
    'your_app_id',              // App ID
    'your_app_secret'           // App Secret
);

try {
    // Send a single message
    $response = $client->sendMessage(
        1,                          // Channel ID
        '13800138000',              // Receiver (phone/email/user ID)
        ['code' => '123456'],       // Template parameters
        'CompanyName'               // Alias from signature_names; required when signature_required is true
    );

    echo "Task ID: " . $response->getTaskId() . "\n";
    echo "Status: " . $response->getStatus() . "\n";

} catch (MessagePushException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getErrorCode() . "\n";
}
```

## Usage

### Send Single Message

```php
$response = $client->sendMessage(
    1,
    '13800138000',
    [
        'code' => '123456',
        'expire_time' => '5'
    ],
    'CompanyName',   // Alias from signature_names; required when signature_required is true
    '2025-12-01T10:00:00Z'  // Optional: Scheduled time (ISO 8601)
);

if ($response->isSuccess()) {
    $taskId = $response->getTaskId();
}
```

### Send Batch Messages

```php
$response = $client->sendBatch(
    1,
    [
        '13800138000',
        '13800138001',
        '13800138002'
    ],
    [
        'content' => 'System maintenance tonight at 22:00',
        'duration' => '2 hours'
    ],
    'CompanyName'
);

echo "Batch ID: " . $response->getBatchId() . "\n";
echo "Total: " . $response->getData()['total_count'] . "\n";
echo "Success: " . $response->getData()['success_count'] . "\n";
```

### Query Task Status

```php
$response = $client->queryTask('550e8400-e29b-41d4-a716-446655440000');

$data = $response->getData();
echo "Status: " . $data['status'] . "\n";
echo "Callback Status: " . ($data['callback_status'] ?? 'N/A') . "\n";
```

### Channel Catalog

Build a message form using the server's channel list and aggregated configuration endpoints. Methods return the existing `Response` wrapper:

```php
// Defaults: all types, page 1, size 20. The server limits page size to 100.
$page = $client->listChannels()->getData();
$smsPage = $client->listChannels('sms', 2, 20)->getData();
foreach ($page['items'] as $channel) {
    echo $channel['id'] . ': ' . $channel['template_name'] . ' (' . $channel['readiness']['state'] . ')' . PHP_EOL;
}

// Use the channel ID selected by the user.
$detail = $client->getChannel(42)->getData();
if ($detail['template'] !== null) {
    echo $detail['template']['content'];
    $variables = $detail['template']['variables'];
}
$signatureRequired = $detail['signature_required'];
$signatureNames = $detail['signature_names'];
```

- List data contains `items/total/page/size`. Each channel contains `id/name/type/message_template_id/template_name/readiness`; detail adds `template/signature_required/signature_names`.
- `template` contains `id/template_name/content_type/content/variables/description` for the system template. `readiness` contains `state` and `blocker_codes`.
- `ready` and `degraded` channels are selectable. `blocked` is still a successful configuration response; disable that option in your UI and show the reason codes as needed.
- `template: null` means missing/deleted. `variables: null` means invalid configuration, while `variables: []` means there are no variables. `getData()` preserves both cases.
- Provide a string value for every returned variable in `$templateParams`. Choose `$signatureName` from `signature_names`, for either SMS signatures or email titles. It is required when `signature_required` is true and may be null otherwise. Empty template parameters are sent as the JSON object `{}`.
- Queries use HMAC authentication and the existing rate limit, without consuming sending quota. URL query parameters are excluded from the signature and GET has no body. Responses are returned without caching, filtering blocked channels, or fetching additional pages.
- The server revalidates configuration when sending. Catalog errors use `MessagePushException` with codes `400/404/500`; authentication or rate limits may use HTTP 200 with a nonzero business code. Invalid JSON and network failures use `RequestException`.

The PHP 7.4-compatible [catalog example](examples/catalog.php) covers list → detail → form values → signature selection → sending. Configure credentials in your backend environment:

```bash
export PUSH_BASE_URL='https://your-domain.com'
export PUSH_APP_ID='your_app_id'
export PUSH_APP_SECRET='your_app_secret'
# Read configuration only.
php examples/catalog.php --channel=42
# Replace the ID, alias, variables, and recipient with your actual selections.
php examples/catalog.php --channel=42 --signature='验证码' --params='{"code":"123456","expire":"5"}' --receiver='13800138000' --send
```

The server must provide `GET /api/v1/channels` and `GET /api/v1/channels/{id}`.

## Response Object

The `Response` class provides convenient methods to access API response data:

| Method | Description |
|--------|-------------|
| `isSuccess()` | Returns `true` if code is 0 |
| `getCode()` | Get response code |
| `getMessage()` | Get response message |
| `getData()` | Get response data array |
| `getTaskId()` | Get task ID (for single message) |
| `getBatchId()` | Get batch ID (for batch message) |
| `getStatus()` | Get task status |
| `toArray()` | Get raw response as array |

## Error Handling

The SDK throws `MessagePushException` on API errors:

```php
use MlievSdk\PushPHP\Exception\MessagePushException;
use MlievSdk\PushPHP\Exception\RequestException;

try {
    $response = $client->sendMessage(1, '13800138000', ['code' => '123456']);
} catch (RequestException $e) {
    // Network or cURL errors
    echo "Request failed: " . $e->getMessage();
} catch (MessagePushException $e) {
    // API errors
    echo "API error: " . $e->getMessage();
    echo "Error code: " . $e->getErrorCode();
    
    // Get full response data
    $responseData = $e->getResponseData();
}
```

### Error Codes

| Range | Category | Examples |
|-------|----------|----------|
| 10xxx | Request errors | Invalid parameters, missing fields |
| 20xxx | Authentication errors | Invalid signature, expired timestamp |
| 30xxx | Business errors | Rate limit, quota exceeded, channel not found |
| 40xxx | System errors | Internal error, service unavailable |

## Configuration

```php
$client = new Client(
    'https://your-domain.com',
    'your_app_id',
    'your_app_secret',
    30  // Request timeout in seconds (default: 10)
);
```

## Message Types

| Type | Value | Description |
|------|-------|-------------|
| SMS | `sms` | Mobile text message |
| Email | `email` | Electronic mail |
| WeChatWork | `wechat_work` | WeChatWork app message |
| DingTalk | `dingtalk` | DingTalk notification |
| Webhook | `webhook` | HTTP callback |
| Push | `push` | APP push notification |

## Task Status

| Status | Value | Description |
|--------|-------|-------------|
| Pending | `pending` | Task created, waiting to send |
| Processing | `processing` | Task is being sent |
| Sent | `sent` | Sent, waiting for callback |
| Success | `success` | Successfully delivered |
| Failed | `failed` | Failed after max retries |

## License

MIT License - see [LICENSE](LICENSE) for details.
