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
        'CompanyName'               // SMS signature (optional)
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
    channelId: 1,
    receiver: '13800138000',
    templateParams: [
        'code' => '123456',
        'expire_time' => '5'
    ],
    signatureName: 'CompanyName',   // Optional: SMS signature
    scheduledAt: '2025-12-01T10:00:00Z'  // Optional: Scheduled time (ISO 8601)
);

if ($response->isSuccess()) {
    $taskId = $response->getTaskId();
}
```

### Send Batch Messages

```php
$response = $client->sendBatch(
    channelId: 1,
    receivers: [
        '13800138000',
        '13800138001',
        '13800138002'
    ],
    templateParams: [
        'content' => 'System maintenance tonight at 22:00',
        'duration' => '2 hours'
    ],
    signatureName: 'CompanyName'
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
    baseUrl: 'https://your-domain.com',
    appId: 'your_app_id',
    appSecret: 'your_app_secret',
    timeout: 30  // Request timeout in seconds (default: 10)
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
