<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\MultipartBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MultipartBuilderTest extends TestCase
{
    public function testFieldSupportsScalarAndNullValues(): void
    {
        $multipart = MultipartBuilder::make()
            ->field('name', 'omega')
            ->field('count', 10)
            ->field('enabled', true)
            ->field('nullable', null);

        $built = $multipart->build();

        self::assertStringContainsString('name="name"', $built['body']);
        self::assertStringContainsString('omega', $built['body']);
        self::assertStringContainsString('name="count"', $built['body']);
        self::assertStringContainsString('10', $built['body']);
        self::assertStringContainsString('name="enabled"', $built['body']);
        self::assertStringContainsString('1', $built['body']);
        self::assertStringContainsString('name="nullable"', $built['body']);
    }

    public function testFileThrowsWhenPathDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Multipart file not found');

        MultipartBuilder::make()->file('document', __DIR__ . '/missing-file.txt');
    }

    public function testBuildCreatesValidMultipartBodyWithFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multipart-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'file-content');

        try {
            $multipart = MultipartBuilder::make()
                ->field('description', 'sample')
                ->file('document', $tmpFile, 'doc.txt', 'text/plain');

            $built = $multipart->build();

            self::assertArrayHasKey('body', $built);
            self::assertArrayHasKey('content_type', $built);
            self::assertStringContainsString('multipart/form-data; boundary=', $built['content_type']);
            self::assertStringContainsString('name="description"', $built['body']);
            self::assertStringContainsString('name="document"; filename="doc.txt"', $built['body']);
            self::assertStringContainsString('Content-Type: text/plain', $built['body']);
            self::assertStringContainsString('file-content', $built['body']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
