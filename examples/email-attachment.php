<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MlievSdk\PushPHP\Client;
use MlievSdk\PushPHP\EmailAttachment;

if ($argc !== 3) {
    fwrite(STDERR, "usage: php examples/email-attachment.php <recipient@example.com> <file>\n");
    exit(1);
}

$baseUrl = getenv('PUSH_BASE_URL');
$appId = getenv('PUSH_APP_ID');
$secret = getenv('PUSH_APP_SECRET');
$channelId = filter_var(getenv('PUSH_CHANNEL_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$baseUrl || !$appId || !$secret || $channelId === false) {
    fwrite(STDERR, "set PUSH_BASE_URL, PUSH_APP_ID, PUSH_APP_SECRET and a positive PUSH_CHANNEL_ID\n");
    exit(1);
}

try {
    $attachment = EmailAttachment::fromFile($argv[2]);
    $client = new Client($baseUrl, $appId, $secret);
    $response = $client->sendMessage(
        $channelId,
        $argv[1],
        [],
        getenv('PUSH_SIGNATURE_NAME') ?: null,
        null,
        [$attachment]
    );
    echo 'accepted task: ' . $response->getTaskId() . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
