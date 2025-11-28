<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Tests;

use MlievSdk\PushPHP\Response\Response;
use PHPUnit\Framework\TestCase;

/**
 * Response class tests
 */
class ResponseTest extends TestCase
{
    /**
     * Test successful response
     */
    public function testSuccessResponse(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'task_id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'pending',
                'created_at' => '2025-11-25T10:00:00Z',
            ],
        ];

        $response = new Response($data);

        $this->assertTrue($response->isSuccess());
        $this->assertEquals(0, $response->getCode());
        $this->assertEquals('success', $response->getMessage());
    }

    /**
     * Test error response
     */
    public function testErrorResponse(): void
    {
        $data = [
            'code' => 20003,
            'message' => 'signature verification failed',
            'data' => null,
        ];

        $response = new Response($data);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals(20003, $response->getCode());
        $this->assertEquals('signature verification failed', $response->getMessage());
    }

    /**
     * Test getData method
     */
    public function testGetData(): void
    {
        $responseData = [
            'task_id' => 'test-task-id',
            'status' => 'success',
        ];

        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => $responseData,
        ];

        $response = new Response($data);

        $this->assertEquals($responseData, $response->getData());
    }

    /**
     * Test getData returns null when no data
     */
    public function testGetDataNull(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
        ];

        $response = new Response($data);

        $this->assertNull($response->getData());
    }

    /**
     * Test getTaskId method
     */
    public function testGetTaskId(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'task_id' => '550e8400-e29b-41d4-a716-446655440000',
            ],
        ];

        $response = new Response($data);

        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $response->getTaskId());
    }

    /**
     * Test getTaskId returns null when not present
     */
    public function testGetTaskIdNull(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [],
        ];

        $response = new Response($data);

        $this->assertNull($response->getTaskId());
    }

    /**
     * Test getBatchId method
     */
    public function testGetBatchId(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'batch_id' => '660e8400-e29b-41d4-a716-446655440001',
                'total_count' => 3,
                'success_count' => 3,
                'failed_count' => 0,
            ],
        ];

        $response = new Response($data);

        $this->assertEquals('660e8400-e29b-41d4-a716-446655440001', $response->getBatchId());
    }

    /**
     * Test getStatus method
     */
    public function testGetStatus(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'task_id' => 'test-id',
                'status' => 'pending',
            ],
        ];

        $response = new Response($data);

        $this->assertEquals('pending', $response->getStatus());
    }

    /**
     * Test getRawResponse method
     */
    public function testGetRawResponse(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'task_id' => 'test-id',
            ],
        ];

        $response = new Response($data);

        $this->assertEquals($data, $response->getRawResponse());
    }

    /**
     * Test toArray method
     */
    public function testToArray(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'task_id' => 'test-id',
            ],
        ];

        $response = new Response($data);

        $this->assertEquals($data, $response->toArray());
    }

    /**
     * Test response with missing fields uses defaults
     */
    public function testMissingFieldsUseDefaults(): void
    {
        $response = new Response([]);

        $this->assertEquals(-1, $response->getCode());
        $this->assertEquals('', $response->getMessage());
        $this->assertNull($response->getData());
        $this->assertFalse($response->isSuccess());
    }

    /**
     * Test batch response data access
     */
    public function testBatchResponseData(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'batch_id' => 'batch-123',
                'total_count' => 100,
                'success_count' => 98,
                'failed_count' => 2,
                'created_at' => '2025-11-25T10:00:00Z',
            ],
        ];

        $response = new Response($data);
        $responseData = $response->getData();

        $this->assertEquals(100, $responseData['total_count']);
        $this->assertEquals(98, $responseData['success_count']);
        $this->assertEquals(2, $responseData['failed_count']);
    }

    /**
     * Test task query response
     */
    public function testTaskQueryResponse(): void
    {
        $data = [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'id' => 1,
                'task_id' => '550e8400-e29b-41d4-a716-446655440000',
                'app_id' => 'test_app_001',
                'channel_id' => 1,
                'message_type' => 'sms',
                'receiver' => '13800138000',
                'content' => '您的验证码是123456，5分钟内有效。',
                'status' => 'success',
                'callback_status' => 'delivered',
                'retry_count' => 0,
                'max_retry' => 3,
                'created_at' => '2025-11-25T10:00:00Z',
                'updated_at' => '2025-11-25T10:00:02Z',
            ],
        ];

        $response = new Response($data);
        $responseData = $response->getData();

        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $response->getTaskId());
        $this->assertEquals('success', $response->getStatus());
        $this->assertEquals('sms', $responseData['message_type']);
        $this->assertEquals('delivered', $responseData['callback_status']);
    }
}

