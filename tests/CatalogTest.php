<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Tests;

use MlievSdk\PushPHP\Client;
use MlievSdk\PushPHP\EmailAttachment;
use MlievSdk\PushPHP\Exception\MessagePushException;
use MlievSdk\PushPHP\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use stdClass;

class CatalogTest extends TestCase
{
    private static string $baseUrl;
    private static string $directory;
    /** @var resource|null */
    private static $server;
    private Client $client;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/push-php-catalog-' . bin2hex(random_bytes(8));
        mkdir(self::$directory, 0700);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Cannot allocate test port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://' . $address;
        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/catalog-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$directory . '/server.log', 'a'], 2 => ['file', self::$directory . '/server.log', 'a']],
            $pipes,
            __DIR__,
            array_merge(getenv(), [
                'CATALOG_TEST_ROUTES' => self::$directory . '/routes.json',
                'CATALOG_TEST_REQUESTS' => self::$directory . '/requests.jsonl',
            ])
        );
        if (!is_resource(self::$server)) {
            throw new \RuntimeException('Cannot start local PHP test server');
        }
        fclose($pipes[0]);
        // Bound startup polling; no external services or network are used.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $context = stream_context_create(['http' => ['timeout' => 0.1]]);
            if (@file_get_contents(self::$baseUrl . '/health', false, $context) === 'ready') {
                return;
            }
            usleep(20000);
        }
        $log = file_get_contents(self::$directory . '/server.log');
        self::tearDownAfterClass();
        throw new \RuntimeException('Local test server did not start: ' . $log);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (glob(self::$directory . '/*') as $file) {
            unlink($file);
        }
        rmdir(self::$directory);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$directory . '/requests.jsonl', '');
        $this->client = new Client(self::$baseUrl, 'catalog-test', 'catalog-test-secret');
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/channel.json'), true);
    }

    private function routes(array $routes): void
    {
        file_put_contents(self::$directory . '/routes.json', json_encode($routes));
    }

    private function success(array $data): array
    {
        return ['body' => ['code' => 0, 'message' => 'success', 'data' => $data]];
    }

    private function requests(): array
    {
        return array_map(static function (string $line): array {
            return json_decode($line, true);
        }, file(self::$directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    // Reconstruct canonical JSON from JSON objects/lists, independently of Client.
    private function canonicalValue($value)
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);
            return (object) array_map([$this, 'canonicalValue'], $properties);
        }
        if (is_array($value)) {
            return array_map([$this, 'canonicalValue'], $value);
        }
        return $value;
    }

    private function assertSignedRequest(array $request): void
    {
        $headers = $request['headers'];
        foreach (['x-app-id', 'x-timestamp', 'x-nonce', 'x-signature'] as $header) {
            self::assertNotEmpty($headers[$header] ?? null, $header);
        }
        self::assertSame('catalog-test', $headers['x-app-id']);
        self::assertMatchesRegularExpression('/^[0-9]+$/', $headers['x-timestamp']);
        $canonical = '';
        if ($request['method'] === 'GET') {
            self::assertSame('', $request['body'], 'GET must have an empty body');
        } else {
            $canonical = json_encode($this->canonicalValue(json_decode($request['body'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $content = $request['method'] . $request['path'] . $canonical . $headers['x-timestamp'] . $headers['x-nonce'];
        self::assertSame(hash_hmac('sha256', $content, 'catalog-test-secret'), $headers['x-signature'], 'Only the path and body, not query parameters, are signed');
    }

    public function testListDefaultsFiltersEncodingAndEmptyPages(): void
    {
        $item = $this->fixture();
        unset($item['template'], $item['signature_names'], $item['signature_required']);
        $page = ['items' => [$item], 'total' => 21, 'page' => 2, 'size' => 5];
        $this->routes(['GET /api/v1/channels' => $this->success($page)]);
        self::assertSame($page, $this->client->listChannels()->getData());
        self::assertSame($page, $this->client->listChannels('sms', 2, 5)->getData());
        $this->client->listChannels('邮件 &+/?', -1, 101);
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'size' => 20];
        $this->routes(['GET /api/v1/channels' => $this->success($empty)]);
        self::assertSame($empty, $this->client->listChannels()->getData());
        $requests = $this->requests();
        self::assertCount(4, $requests);
        self::assertSame(['page' => '1', 'page_size' => '20'], $requests[0]['query']);
        self::assertSame(['page' => '2', 'page_size' => '5', 'type' => 'sms'], $requests[1]['query']);
        self::assertSame(['page' => '-1', 'page_size' => '101', 'type' => '邮件 &+/?'], $requests[2]['query']);
        foreach ($requests as $request) {
            self::assertSame('GET', $request['method']);
            self::assertSame('/api/v1/channels', $request['path']);
            $this->assertSignedRequest($request);
        }
    }

    public function testCompleteDetailsPreserveReadinessAndNullValues(): void
    {
        foreach (['ready', 'degraded', 'missing template', 'invalid variables', 'no variables or signature'] as $scenario) {
            $data = $this->fixture();
            if ($scenario === 'degraded') {
                $data['readiness'] = ['state' => 'degraded', 'blocker_codes' => ['PROVIDER_ACCOUNT_UNAVAILABLE']];
            } elseif ($scenario === 'missing template' || $scenario === 'invalid variables') {
                $code = $scenario === 'missing template' ? 'MESSAGE_TEMPLATE_MISSING' : 'MESSAGE_TEMPLATE_VARIABLES_INVALID';
                $data['readiness'] = ['state' => 'blocked', 'blocker_codes' => [$code]];
                if ($scenario === 'missing template') {
                    $data['template'] = null;
                } else {
                    $data['template']['variables'] = null;
                }
                $data['signature_required'] = false;
                $data['signature_names'] = [];
            } elseif ($scenario === 'no variables or signature') {
                $data['template']['variables'] = [];
                $data['signature_required'] = false;
                $data['signature_names'] = [];
            }
            $this->routes(['GET /api/v1/channels/42' => $this->success($data)]);
            $response = $this->client->getChannel(42);
            self::assertTrue($response->isSuccess(), $scenario);
            self::assertSame($data, $response->getData(), $scenario);
        }
        foreach ($this->requests() as $request) {
            self::assertSame('/api/v1/channels/42', $request['path']);
            self::assertSame([], $request['query']);
            $this->assertSignedRequest($request);
        }
    }

    public function testHTTPAndBusinessErrorsPreserveCodeMessageAndResponse(): void
    {
        foreach ([[400, 400], [404, 404], [500, 500], [200, 20003], [200, 30001]] as [$status, $code]) {
            $body = ['code' => $code, 'message' => 'catalog failure', 'data' => null];
            $reply = ['status' => $status, 'body' => $body];
            $this->routes(['GET /api/v1/channels' => $reply, 'GET /api/v1/channels/42' => $reply]);
            foreach (['listChannels' => [], 'getChannel' => [42]] as $method => $arguments) {
                try {
                    $this->client->$method(...$arguments);
                    self::fail('Expected API error');
                } catch (MessagePushException $e) {
                    self::assertNotInstanceOf(RequestException::class, $e);
                    self::assertSame($code, $e->getErrorCode());
                    self::assertSame('catalog failure', $e->getMessage());
                    self::assertSame($body, $e->getResponseData());
                }
            }
        }
    }

    public function testInvalidJSONAndNetworkErrorsUseRequestException(): void
    {
        $reply = ['status' => 502, 'raw' => 'not JSON'];
        $this->routes(['GET /api/v1/channels' => $reply, 'GET /api/v1/channels/42' => $reply]);
        foreach (['listChannels' => [], 'getChannel' => [42]] as $method => $arguments) {
            try {
                $this->client->$method(...$arguments);
                self::fail('Expected invalid JSON error');
            } catch (RequestException $e) {
                self::assertSame(502, $e->getErrorCode());
                self::assertStringContainsString('Invalid JSON response', $e->getMessage());
            }
        }
        // Reserve a local TCP port without listening, so connections are refused.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($socket);
        $client = new Client('http://' . stream_socket_get_name($socket, false), 'catalog-test', 'catalog-test-secret', 1);
        try {
            foreach (['listChannels' => [], 'getChannel' => [42]] as $method => $arguments) {
                try {
                    $client->$method(...$arguments);
                    self::fail('Expected network error');
                } catch (RequestException $e) {
                    self::assertStringContainsString('cURL error', $e->getMessage());
                }
            }
        } finally {
            fclose($socket);
        }
    }

    public function testSelectionsCanBeSentAndExistingMethodsStillSignCorrectly(): void
    {
        $detail = $this->fixture();
        $this->routes([
            'GET /api/v1/channels' => $this->success(['items' => [$detail], 'total' => 1, 'page' => 1, 'size' => 20]),
            'GET /api/v1/channels/42' => $this->success($detail),
            'POST /api/v1/messages' => $this->success(['task_id' => 'catalog-task', 'status' => 'pending']),
            'POST /api/v1/messages/batch' => $this->success(['batch_id' => 'catalog-batch']),
            'GET /api/v1/messages/catalog-task' => $this->success(['task_id' => 'catalog-task', 'status' => 'success']),
        ]);
        $page = $this->client->listChannels()->getData();
        $selected = $this->client->getChannel($page['items'][0]['id'])->getData();
        $formValues = ['code' => '123456', 'expire' => '5'];
        $params = [];
        foreach ($selected['template']['variables'] as $variable) {
            $params[$variable] = $formValues[$variable];
        }
        $alias = $selected['signature_names'][0];
        $sent = $this->client->sendMessage($selected['id'], '13800138000', $params, $alias);
        self::assertSame('catalog-task', $sent->getTaskId());
        self::assertSame('catalog-batch', $this->client->sendBatch($selected['id'], ['13800138000'], $params, $alias)->getBatchId());
        self::assertSame('success', $this->client->queryTask($sent->getTaskId())->getStatus());
        $requests = $this->requests();
        self::assertCount(5, $requests);
        foreach ($requests as $request) {
            $this->assertSignedRequest($request);
            if ($request['method'] === 'POST') {
                $body = json_decode($request['body'], true);
                self::assertSame(42, $body['channel_id']);
                self::assertSame($alias, $body['signature_name']);
                self::assertSame($params, $body['template_params']);
            }
        }
    }

    public function testTemplateWithoutVariablesSendsAnEmptyJSONObject(): void
    {
        $detail = $this->fixture();
        $detail['template']['variables'] = [];
        $detail['signature_required'] = false;
        $detail['signature_names'] = [];
        $this->routes([
            'GET /api/v1/channels/42' => $this->success($detail),
            'POST /api/v1/messages' => $this->success(['task_id' => 'no-variables']),
            'POST /api/v1/messages/batch' => $this->success(['batch_id' => 'no-variables']),
        ]);
        $selected = $this->client->getChannel(42)->getData();
        self::assertSame([], $selected['template']['variables']);
        $this->client->sendMessage($selected['id'], '13800138000');
        $this->client->sendBatch($selected['id'], ['13800138000']);
        foreach ($this->requests() as $request) {
            $this->assertSignedRequest($request);
            if ($request['method'] === 'POST') {
                $body = json_decode($request['body']);
                self::assertInstanceOf(stdClass::class, $body->template_params, 'The Go API expects an object, including when empty');
                self::assertSame([], get_object_vars($body->template_params));
                self::assertFalse(property_exists($body, 'signature_name'));
            }
        }
    }

    public function testSingleAndBatchAttachmentsAreSerializedAndSigned(): void
    {
        $this->routes([
            'POST /api/v1/messages' => $this->success(['task_id' => 'attachment-task']),
            'POST /api/v1/messages/batch' => $this->success(['batch_id' => 'attachment-batch']),
        ]);
        $attachments = [
            EmailAttachment::fromContent('报告.txt', 'hello', 'text/plain'),
            [
                'filename' => 'data.bin',
                'content_type' => 'application/octet-stream',
                'content_base64' => 'AAEC',
            ],
        ];

        $this->client->sendMessage(42, 'first@example.com', [], '通知', null, $attachments);
        $this->client->sendBatch(42, ['first@example.com', 'second@example.com'], [], '通知', null, $attachments);

        $requests = $this->requests();
        self::assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSignedRequest($request);
            $body = json_decode($request['body'], true);
            self::assertSame('报告.txt', $body['attachments'][0]['filename']);
            self::assertSame('aGVsbG8=', $body['attachments'][0]['content_base64']);
            self::assertSame('AAEC', $body['attachments'][1]['content_base64']);
        }
    }
}
