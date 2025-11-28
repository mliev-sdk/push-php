# Mliev 消息推送 PHP SDK

[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Mliev 消息推送服务的 PHP SDK。支持短信、邮件、企业微信、钉钉、Webhook 等多种消息通道。

[English](README.md)

## 环境要求

- PHP 7.4+
- cURL 扩展
- JSON 扩展

## 安装

```bash
composer require mliev/message-push
```

## 快速开始

```php
<?php

require 'vendor/autoload.php';

use MlievSdk\PushPHP\Client;
use MlievSdk\PushPHP\Exception\MessagePushException;

$client = new Client(
    'https://your-domain.com',  // API 基础地址
    'your_app_id',              // 应用 ID
    'your_app_secret'           // 应用密钥
);

try {
    // 发送单条消息
    $response = $client->sendMessage(
        1,                          // 通道 ID
        '13800138000',              // 接收者（手机号/邮箱/用户ID）
        ['code' => '123456'],       // 模板参数
        '公司名称'                   // 短信签名（可选）
    );

    echo "任务 ID: " . $response->getTaskId() . "\n";
    echo "状态: " . $response->getStatus() . "\n";

} catch (MessagePushException $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "错误码: " . $e->getErrorCode() . "\n";
}
```

## 使用方法

### 发送单条消息

```php
$response = $client->sendMessage(
    channelId: 1,
    receiver: '13800138000',
    templateParams: [
        'code' => '123456',
        'expire_time' => '5'
    ],
    signatureName: '公司名称',   // 可选：短信签名
    scheduledAt: '2025-12-01T10:00:00Z'  // 可选：定时发送时间（ISO 8601 格式）
);

if ($response->isSuccess()) {
    $taskId = $response->getTaskId();
}
```

### 批量发送消息

```php
$response = $client->sendBatch(
    channelId: 1,
    receivers: [
        '13800138000',
        '13800138001',
        '13800138002'
    ],
    templateParams: [
        'content' => '系统将于今晚22:00进行维护',
        'duration' => '2小时'
    ],
    signatureName: '公司名称'
);

echo "批次 ID: " . $response->getBatchId() . "\n";
echo "总数: " . $response->getData()['total_count'] . "\n";
echo "成功: " . $response->getData()['success_count'] . "\n";
```

### 查询任务状态

```php
$response = $client->queryTask('550e8400-e29b-41d4-a716-446655440000');

$data = $response->getData();
echo "状态: " . $data['status'] . "\n";
echo "回调状态: " . ($data['callback_status'] ?? '无') . "\n";
```

## 响应对象

`Response` 类提供了便捷的方法来访问 API 响应数据：

| 方法 | 说明 |
|------|------|
| `isSuccess()` | 如果 code 为 0 则返回 `true` |
| `getCode()` | 获取响应码 |
| `getMessage()` | 获取响应消息 |
| `getData()` | 获取响应数据数组 |
| `getTaskId()` | 获取任务 ID（单条消息） |
| `getBatchId()` | 获取批次 ID（批量消息） |
| `getStatus()` | 获取任务状态 |
| `toArray()` | 获取原始响应数组 |

## 错误处理

SDK 在 API 错误时会抛出 `MessagePushException` 异常：

```php
use MlievSdk\PushPHP\Exception\MessagePushException;
use MlievSdk\PushPHP\Exception\RequestException;

try {
    $response = $client->sendMessage(1, '13800138000', ['code' => '123456']);
} catch (RequestException $e) {
    // 网络或 cURL 错误
    echo "请求失败: " . $e->getMessage();
} catch (MessagePushException $e) {
    // API 错误
    echo "API 错误: " . $e->getMessage();
    echo "错误码: " . $e->getErrorCode();
    
    // 获取完整响应数据
    $responseData = $e->getResponseData();
}
```

### 错误码说明

| 范围 | 类别 | 示例 |
|------|------|------|
| 10xxx | 请求错误 | 参数无效、缺少必填字段 |
| 20xxx | 认证错误 | 签名无效、时间戳过期 |
| 30xxx | 业务错误 | 超出速率限制、配额不足、通道不存在 |
| 40xxx | 系统错误 | 内部错误、服务不可用 |

## 配置选项

```php
$client = new Client(
    baseUrl: 'https://your-domain.com',
    appId: 'your_app_id',
    appSecret: 'your_app_secret',
    timeout: 30  // 请求超时时间，单位秒（默认：10）
);
```

## 消息类型

| 类型 | 值 | 说明 |
|------|------|------|
| 短信 | `sms` | 手机短信 |
| 邮件 | `email` | 电子邮件 |
| 企业微信 | `wechat_work` | 企业微信应用消息 |
| 钉钉 | `dingtalk` | 钉钉工作通知 |
| Webhook | `webhook` | HTTP 回调 |
| 推送通知 | `push` | APP 推送通知 |

## 任务状态

| 状态 | 值 | 说明 |
|------|------|------|
| 待处理 | `pending` | 任务已创建，等待发送 |
| 处理中 | `processing` | 任务正在发送 |
| 已发送 | `sent` | 已发送，等待回调确认 |
| 成功 | `success` | 发送成功 |
| 失败 | `failed` | 发送失败（已达最大重试次数） |

## 回调状态

| 状态 | 值 | 说明 |
|------|------|------|
| 已送达 | `delivered` | 消息已送达接收者 |
| 发送失败 | `failed` | 运营商/服务商发送失败 |
| 被拒绝 | `rejected` | 接收者拒收 |
| 超时 | `timeout` | 等待回调超时 |

## 最佳实践

1. **保存任务 ID**：保存返回的 `task_id` 便于后续查询和问题排查
2. **设置合理超时**：生产环境建议设置 10-30 秒的超时时间
3. **批量限制**：单次批量发送建议不超过 500 条
4. **重试策略**：服务端已内置重试机制，客户端无需重复提交同一任务
5. **密钥保护**：`app_secret` 应妥善保管，不要在前端代码或公开环境中暴露

## 许可证

MIT License - 详见 [LICENSE](LICENSE)

