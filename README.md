# Mliev Message Push PHP SDK

[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

PHP SDK for Mliev Message Push Service. Supports SMS, Email, WeChatWork, DingTalk, and Webhook messaging.

[中文文档](README.zh-cn.md)

## Requirements

- PHP 7.4+
- cURL extension
- JSON extension

## Message language

Bind a generic English template as `en` to handle `en`, `en-US`, and `en-GB` on one channel. Add an `en-GB` binding when you have UK-specific copy. Omitting the request locale starts matching at the channel default.

| Requested locale | Templates tried in order |
| --- | --- |
| `en-GB` | `en-GB` → `en` |
| `zh-TW`, `zh-HK`, `zh-MO` | Requested region → `zh-Hant` → `zh` |
| `zh-CN`, `zh-SG` | Requested region → `zh-Hans` → `zh` |
| `zh-Hant` or `zh-Hans` | Requested script → `zh` |
| `zh-Hant-CN` | `zh-Hant-CN` → `zh-Hant` → `zh` (explicit Traditional script) |
| `zh` | Generic `zh` |

If every candidate is unavailable, the channel's `reject` policy returns an error and `use_default` tries the channel default. The server validates the signature against the selected language group. Preview and send with the same requested locale, and choose the signature from that preview.

## Installation

```bash
composer require mliev-sdk/push-php
```

Language preview requires support in both the server and SDK. Projects using `v1.2.0` can use the following command to pin a commit that supports the locale API.

```bash
composer require 'mliev-sdk/push-php:dev-main#ed890dbae327b017c1da018b1237b48c13cf7956'
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
    'Notice',    // Choose from signature_names in the locale preview
    null,        // scheduledAt accepts an ISO 8601 timestamp
    [],          // attachments
    'en-GB'      // locale
);

if ($response->isSuccess()) {
    $taskId = $response->getTaskId();
    $resolvedLocale = $response->getData()['resolved_locale'] ?? null;
}
```

### Send Batch Messages

All recipients in a batch share the requested locale, template parameters, and signature. Group recipients by locale before submitting.

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
    'Notice',
    null,
    [],
    'en-GB'
);

$resolvedLocale = $response->getData()['resolved_locale'] ?? null;
echo "Batch ID: " . $response->getBatchId() . "\n";
echo "Total: " . $response->getData()['total_count'] . "\n";
echo "Success: " . $response->getData()['success_count'] . "\n";
```

### Email Attachments

Email channels support attachments for single and batch sends. Use the value object for local files:

```php
use MlievSdk\PushPHP\EmailAttachment;

$attachment = EmailAttachment::fromFile('./invoice.pdf');
$response = $client->sendMessage(
    12,
    'customer@example.com',
    ['order_id' => 'ORDER-1001'],
    'invoice-ready', // Email title alias
    null,            // Optional scheduled time
    [$attachment]
);
```

Generated content and pre-encoded content are supported as well:

```php
$generated = EmailAttachment::fromContent('report.csv', "name,total\nAlice,42\n", 'text/csv');
$encoded = EmailAttachment::fromBase64('data.bin', 'AAEC', 'application/octet-stream');
```

Pass the same attachment array as the final batch argument; every recipient receives it:

```php
$response = $client->sendBatch(12, ['a@example.com', 'b@example.com'], [], 'invoice-ready', null, [$attachment]);
```

The final parameter also accepts wire-format arrays containing `filename`, optional `content_type`, and `content_base64`. Base64 must not include a `data:` URL prefix. Batch recipients share the same attachments. The server defaults to 5 attachments, 5 MiB per file, and 10 MiB total, but operators can change those limits; the SDK deliberately does not hard-code them. File helpers read the full file into memory.

See the runnable [email attachment example](examples/email-attachment.php).

### Query Task Status

```php
$response = $client->queryTask('550e8400-e29b-41d4-a716-446655440000');

$data = $response->getData();
$requestedLocale = $data['requested_locale'] ?? null;
$resolvedLocale = $data['resolved_locale'] ?? null;
echo "Status: " . $data['status'] . "\n";
echo "Callback Status: " . ($data['callback_status'] ?? 'N/A') . "\n";
```

If an `en-GB` request matched generic English, `requested_locale` is `en-GB` and `resolved_locale` is `en`. Omitting the request locale leaves the former absent. Older tasks may also omit the latter. Scheduled delivery and retries use the locale selected at acceptance.

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
$detail = $client->getChannel(42, 'en-GB')->getData();
$matchedLocale = $detail['resolved_locale'] ?? null;
if ($detail['template'] !== null) {
    echo $detail['template']['content'];
    $variables = $detail['template']['variables'];
}
$signatureRequired = $detail['signature_required'];
$signatureNames = $detail['signature_names'];
```

- List data contains `items/total/page/size`. Each channel contains `id/name/type/message_template_id/template_name/readiness`; detail adds `template/signature_required/signature_names`.
- `getChannel($id)` previews the default locale. List items expose `default_locale`, `locale_fallback_policy`, and readiness for the default. In a locale preview, top-level readiness and signature options apply to `resolved_locale`. `locales` lists the configured groups.
- `template` contains `id/template_name/content_type/content/variables/description` for the system template. `readiness` contains `state` and `blocker_codes`.
- A preview with state `ready` or `degraded` can send. If it is `blocked`, inspect `blocker_codes` and allow the user to preview another locale on the same channel.
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
php examples/catalog.php --channel=42 --locale=en-GB
# Replace the ID, alias, variables, and recipient with your actual selections.
php examples/catalog.php --channel=42 --locale=en-GB --signature='Notice' --params='{"code":"123456","expire":"5"}' --receiver='13800138000' --send
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
