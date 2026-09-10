<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP\Tests;

use InvalidArgumentException;
use MlievSdk\PushPHP\EmailAttachment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailAttachmentTest extends TestCase
{
    public function testFromContentEncodesBytesAndDetectsMime(): void
    {
        $attachment = EmailAttachment::fromContent('报告.txt', 'hello')->toArray();

        self::assertSame('报告.txt', $attachment['filename']);
        self::assertSame('aGVsbG8=', $attachment['content_base64']);
        if (isset($attachment['content_type'])) {
            self::assertStringStartsWith('text/plain', $attachment['content_type']);
        }
    }

    public function testFromFileUsesBasenameAndOptionalOverride(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mliev-attachment-');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4\n");

        try {
            $attachment = EmailAttachment::fromFile($path, 'invoice.pdf', 'application/pdf')->toArray();
            self::assertSame('invoice.pdf', $attachment['filename']);
            self::assertSame('application/pdf', $attachment['content_type']);
            self::assertSame(base64_encode("%PDF-1.4\n"), $attachment['content_base64']);
        } finally {
            unlink($path);
        }
    }

    public function testFromBase64AcceptsCanonicalWireValue(): void
    {
        self::assertSame([
            'filename' => 'data.bin',
            'content_base64' => 'AAEC',
            'content_type' => 'application/octet-stream',
        ], EmailAttachment::fromBase64('data.bin', 'AAEC', 'application/octet-stream')->toArray());

        self::assertSame(
            'text/csv; charset=utf-8',
            EmailAttachment::fromBase64('data.csv', 'eA==', 'text/csv; charset=utf-8')->toArray()['content_type']
        );
    }

    /**
     * @dataProvider invalidAttachmentProvider
     */
    public function testRejectsInvalidAttachment(string $filename, string $contentBase64, ?string $contentType): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailAttachment::fromBase64($filename, $contentBase64, $contentType);
    }

    public function invalidAttachmentProvider(): array
    {
        return [
            'path filename' => ['../secret.txt', 'eA==', null],
            'header filename' => ["safe.txt\r\nBcc: bad@example.com", 'eA==', null],
            'empty content' => ['safe.txt', '', null],
            'invalid base64' => ['safe.txt', '%%%', null],
            'data URL' => ['safe.txt', 'data:text/plain;base64,eA==', null],
            'invalid MIME' => ['safe.txt', 'eA==', "text/plain\r\nX-Test: bad"],
            'invalid MIME parameter' => ['safe.txt', 'eA==', 'text/plain; broken'],
        ];
    }

    public function testMissingFileThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        EmailAttachment::fromFile(__DIR__ . '/does-not-exist.pdf');
    }
}
