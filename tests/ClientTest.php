<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Tests;

use MlievSdk\PushPHP\Client;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Client class tests
 */
class ClientTest extends TestCase
{
    private Client $client;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->client = new Client(
            'https://example.com',
            'test_app_id',
            'secret123456'
        );
        $this->reflection = new ReflectionClass($this->client);
    }

    /**
     * Get private method for testing
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Test client initialization
     */
    public function testClientConstruction(): void
    {
        $client = new Client(
            'https://api.example.com/',
            'my_app_id',
            'my_secret',
            30
        );

        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test base URL trailing slash is trimmed
     */
    public function testBaseUrlTrailingSlashTrimmed(): void
    {
        $client = new Client(
            'https://api.example.com/',
            'app_id',
            'secret'
        );

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('baseUrl');
        $property->setAccessible(true);

        $this->assertEquals('https://api.example.com', $property->getValue($client));
    }

    /**
     * Test sortParams with empty array
     */
    public function testSortParamsEmpty(): void
    {
        $method = $this->getPrivateMethod('sortParams');
        
        $this->assertEquals('', $method->invoke($this->client, null));
        $this->assertEquals('', $method->invoke($this->client, []));
    }

    /**
     * Test sortParams with simple array
     */
    public function testSortParamsSimple(): void
    {
        $method = $this->getPrivateMethod('sortParams');
        
        $params = [
            'zebra' => 'last',
            'apple' => 'first',
            'mango' => 'middle',
        ];

        $result = $method->invoke($this->client, $params);
        $decoded = json_decode($result, true);

        // Verify keys are sorted
        $keys = array_keys($decoded);
        $this->assertEquals(['apple', 'mango', 'zebra'], $keys);
    }

    /**
     * Test sortParams with nested array
     */
    public function testSortParamsNested(): void
    {
        $method = $this->getPrivateMethod('sortParams');
        
        $params = [
            'receiver' => '13800138000',
            'channel_id' => 1,
            'template_params' => [
                'expire_time' => '5',
                'code' => '123456',
            ],
        ];

        $result = $method->invoke($this->client, $params);
        $decoded = json_decode($result, true);

        // Verify top-level keys are sorted
        $keys = array_keys($decoded);
        $this->assertEquals(['channel_id', 'receiver', 'template_params'], $keys);

        // Verify nested keys are sorted
        $nestedKeys = array_keys($decoded['template_params']);
        $this->assertEquals(['code', 'expire_time'], $nestedKeys);
    }

    /**
     * Test sortParams preserves Unicode
     */
    public function testSortParamsUnicode(): void
    {
        $method = $this->getPrivateMethod('sortParams');
        
        $params = [
            'signature_name' => '公司名称',
            'receiver' => '13800138000',
        ];

        $result = $method->invoke($this->client, $params);

        // Should not escape Unicode
        $this->assertStringContainsString('公司名称', $result);
        $this->assertStringNotContainsString('\u', $result);
    }

    /**
     * Test recursiveKeySort
     */
    public function testRecursiveKeySort(): void
    {
        $method = $this->getPrivateMethod('recursiveKeySort');
        
        $input = [
            'z' => 'last',
            'a' => 'first',
            'nested' => [
                'b' => 2,
                'a' => 1,
            ],
        ];

        $result = $method->invoke($this->client, $input);

        // Check order by converting to JSON and comparing structure
        $keys = array_keys($result);
        $this->assertEquals(['a', 'nested', 'z'], $keys);

        $nestedKeys = array_keys($result['nested']);
        $this->assertEquals(['a', 'b'], $nestedKeys);
    }

    /**
     * Test signature generation
     */
    public function testGenerateSignature(): void
    {
        $method = $this->getPrivateMethod('generateSignature');
        
        $signature = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            ['channel_id' => 1, 'receiver' => '13800138000'],
            '1700000000',
            'abc123'
        );

        // Signature should be a 64-character hex string (SHA256)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    /**
     * Test signature is consistent for same input
     */
    public function testSignatureConsistency(): void
    {
        $method = $this->getPrivateMethod('generateSignature');
        
        $params = [
            'channel_id' => 1,
            'receiver' => '13800138000',
            'template_params' => ['code' => '123456'],
        ];

        $sig1 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'nonce123'
        );

        $sig2 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'nonce123'
        );

        $this->assertEquals($sig1, $sig2);
    }

    /**
     * Test signature changes with different nonce
     */
    public function testSignatureChangesWithNonce(): void
    {
        $method = $this->getPrivateMethod('generateSignature');
        
        $params = ['channel_id' => 1, 'receiver' => '13800138000'];

        $sig1 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'nonce1'
        );

        $sig2 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'nonce2'
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test signature changes with different timestamp
     */
    public function testSignatureChangesWithTimestamp(): void
    {
        $method = $this->getPrivateMethod('generateSignature');
        
        $params = ['channel_id' => 1, 'receiver' => '13800138000'];

        $sig1 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'nonce'
        );

        $sig2 = $method->invoke(
            $this->client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000001',
            'nonce'
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test signature for GET request (no params)
     */
    public function testSignatureForGetRequest(): void
    {
        $method = $this->getPrivateMethod('generateSignature');
        
        $signature = $method->invoke(
            $this->client,
            'GET',
            '/api/v1/messages/task-id-123',
            null,
            '1700000000',
            'abc123'
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    /**
     * Test signature matches expected value from API documentation
     * 
     * Based on the example in API_INTEGRATION.md:
     * - app_secret = secret123456
     * - method = POST
     * - path = /api/v1/messages
     * - timestamp = 1700000000
     * - nonce = abc123
     * - request_body = {"channel_id":1,"receiver":"13800138000","template_params":{"code":"123456"}}
     */
    public function testSignatureMatchesDocumentation(): void
    {
        $client = new Client(
            'https://example.com',
            'test_app',
            'secret123456'
        );

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('generateSignature');
        $method->setAccessible(true);

        $params = [
            'channel_id' => 1,
            'receiver' => '13800138000',
            'template_params' => [
                'code' => '123456',
            ],
        ];

        $signature = $method->invoke(
            $client,
            'POST',
            '/api/v1/messages',
            $params,
            '1700000000',
            'abc123'
        );

        // The sign content should be:
        // POST/api/v1/messages{"channel_id":1,"receiver":"13800138000","template_params":{"code":"123456"}}1700000000abc123
        $expectedSignContent = 'POST/api/v1/messages{"channel_id":1,"receiver":"13800138000","template_params":{"code":"123456"}}1700000000abc123';
        $expectedSignature = hash_hmac('sha256', $expectedSignContent, 'secret123456');

        $this->assertEquals($expectedSignature, $signature);
    }
}

