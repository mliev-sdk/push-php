<?php

declare(strict_types=1);

namespace MlievSdk\PushPHP;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable email attachment value object.
 */
final class EmailAttachment
{
    private string $filename;
    private string $contentBase64;
    private ?string $contentType;

    private function __construct(string $filename, string $contentBase64, ?string $contentType)
    {
        $this->filename = self::validateFilename($filename);
        $this->contentBase64 = self::validateBase64($contentBase64);
        $this->contentType = self::validateContentType($contentType);
    }

    /**
     * Create an attachment from raw in-memory bytes.
     */
    public static function fromContent(
        string $filename,
        string $content,
        ?string $contentType = null
    ): self {
        if ($content === '') {
            throw new InvalidArgumentException('Attachment content must not be empty');
        }

        return new self(
            $filename,
            base64_encode($content),
            $contentType ?? self::detectContentType($content)
        );
    }

    /**
     * Create an attachment by reading a local file in full.
     */
    public static function fromFile(
        string $path,
        ?string $filename = null,
        ?string $contentType = null
    ): self {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Email attachment is not a readable file: %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to read email attachment: %s', $path));
        }

        if ($contentType === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                $contentType = $detected;
            }
        }

        return self::fromContent($filename ?? basename($path), $content, $contentType);
    }

    /**
     * Create an attachment from an already encoded RFC 4648 Base64 string.
     */
    public static function fromBase64(
        string $filename,
        string $contentBase64,
        ?string $contentType = null
    ): self {
        return new self($filename, $contentBase64, $contentType);
    }

    /**
     * Convert the attachment to the public API wire format.
     */
    public function toArray(): array
    {
        $result = [
            'filename' => $this->filename,
            'content_base64' => $this->contentBase64,
        ];
        if ($this->contentType !== null) {
            $result['content_type'] = $this->contentType;
        }

        return $result;
    }

    private static function validateFilename(string $value): string
    {
        $filename = trim($value);
        if ($filename === '') {
            throw new InvalidArgumentException('Attachment filename must not be empty');
        }
        if (preg_match('//u', $filename) !== 1) {
            throw new InvalidArgumentException('Attachment filename must be valid UTF-8');
        }
        if (strlen($filename) > 255) {
            throw new InvalidArgumentException('Attachment filename must not exceed 255 bytes');
        }
        if ($filename === '.' || $filename === '..' || preg_match('/[\\\\\/]/', $filename) === 1) {
            throw new InvalidArgumentException('Attachment filename must not contain path separators');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1) {
            throw new InvalidArgumentException('Attachment filename must not contain control characters');
        }

        return $filename;
    }

    private static function validateBase64(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Attachment content_base64 must not be empty');
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '' || base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException('Attachment content_base64 must be standard Base64 without a data URL prefix');
        }

        return $value;
    }

    private static function validateContentType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $contentType = trim($value);
        if (preg_match('/[\x00-\x1F\x7F]/', $contentType) === 1) {
            throw new InvalidArgumentException('Attachment content type must not contain control characters');
        }
        $token = "[A-Za-z0-9!#\$%&'*+.^_`|~-]+";
        $parameterValue = '(?:' . $token . '|"(?:[^"\\\\]|\\\\.)*")';
        $pattern = '@^' . $token . '/' . $token
            . '(?:\s*;\s*' . $token . '\s*=\s*' . $parameterValue . ')*$@D';
        if (preg_match($pattern, $contentType) !== 1) {
            throw new InvalidArgumentException('Attachment content type must be a valid MIME media type');
        }

        return $contentType;
    }

    private static function detectContentType(string $content): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }
}
