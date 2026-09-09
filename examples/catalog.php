<?php

declare(strict_types=1);

// Lists configuration by default. Sending requires an explicit --send flag.
require __DIR__ . '/../vendor/autoload.php';

use MlievSdk\PushPHP\Client;

try {
    $baseUrl = getenv('PUSH_BASE_URL');
    $appId = getenv('PUSH_APP_ID');
    $secret = getenv('PUSH_APP_SECRET');
    if (!$baseUrl || !$appId || !$secret) {
        throw new RuntimeException('Set PUSH_BASE_URL, PUSH_APP_ID and PUSH_APP_SECRET');
    }
    $options = getopt('', ['channel:', 'signature:', 'params:', 'receiver:', 'send']);
    $client = new Client($baseUrl, $appId, $secret);
    $page = $client->listChannels()->getData();
    echo json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!isset($options['channel'])) {
        if (isset($options['send'])) {
            throw new RuntimeException('Select --channel before sending');
        }
        exit(0);
    }
    $channelId = filter_var($options['channel'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($channelId === false) {
        throw new RuntimeException('--channel must be a positive integer');
    }
    $detail = $client->getChannel($channelId)->getData();
    echo json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (!isset($options['send'])) {
        exit(0);
    }
    if ($detail['readiness']['state'] === 'blocked' || $detail['template'] === null || $detail['template']['variables'] === null) {
        throw new RuntimeException('Channel unavailable: ' . implode(', ', $detail['readiness']['blocker_codes']));
    }
    $values = json_decode($options['params'] ?? '{}');
    if (!$values instanceof stdClass) {
        throw new RuntimeException('--params must be a JSON object of strings');
    }
    $params = get_object_vars($values);
    foreach ($params as $value) {
        if (!is_string($value)) {
            throw new RuntimeException('Template variable values must be strings');
        }
    }
    foreach ($detail['template']['variables'] as $variable) {
        if (!array_key_exists($variable, $params)) {
            throw new RuntimeException('Missing template variable: ' . $variable);
        }
    }
    $signature = $options['signature'] ?? null;
    if (($detail['signature_required'] || $signature !== null) && !in_array($signature, $detail['signature_names'], true)) {
        throw new RuntimeException('Choose --signature from signature_names');
    }
    $receiver = $options['receiver'] ?? '';
    if ($receiver === '') {
        throw new RuntimeException('Provide --receiver for sending');
    }
    $sent = $client->sendMessage($detail['id'], $receiver, $params, $signature);
    echo json_encode($sent->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
