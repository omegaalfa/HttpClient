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

    public function testBuildEscapesQuotesInFieldAndFileNames(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multipart-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'x');

        try {
            $multipart = MultipartBuilder::make()
                ->field('evil"name', 'v')
                ->file('doc"ument', $tmpFile, 'x"y.txt');

            $built = $multipart->build();

            self::assertStringContainsString('name="evil%22name"', $built['body']);
            self::assertStringContainsString('name="doc%22ument"; filename="x%22y.txt"', $built['body']);
            self::assertStringNotContainsString('name="evil"', $built['body']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testBuildNeutralizesControlCharsPreventingHeaderInjection(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multipart-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'x');

        try {
            $multipart = MultipartBuilder::make()
                ->field("name\r\nX-Injected: pwned", 'v')
                ->file('doc', $tmpFile, "f\r\nX-Injected: pwned.txt");

            $built = $multipart->build();

            self::assertStringContainsString('name="name%0D%0AX-Injected: pwned"', $built['body']);
            self::assertStringContainsString('filename="f%0D%0AX-Injected: pwned.txt"', $built['body']);
            self::assertStringNotContainsString("\r\nX-Injected: pwned\r\n", $built['body']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testBuildToStreamProducesValidMultipartBody(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multipart-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'streamed-content');

        try {
            $multipart = MultipartBuilder::make()
                ->field('description', 'sample')
                ->file('document', $tmpFile, 'doc.txt', 'text/plain');

            $built = $multipart->buildToStream();
            rewind($built['stream']);
            $body = stream_get_contents($built['stream']);
            fclose($built['stream']);
            self::assertIsString($body);

            self::assertStringContainsString('multipart/form-data; boundary=', $built['content_type']);
            self::assertStringContainsString('name="description"', $body);
            self::assertStringContainsString('name="document"; filename="doc.txt"', $body);
            self::assertStringContainsString('Content-Type: text/plain', $body);
            self::assertStringContainsString('streamed-content', $body);
            self::assertSame(strlen($body), $built['content_length']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testBuildToStreamKeepsLargeUploadsOutOfMemory(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multipart-test-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, str_repeat('x', 5 * 1024 * 1024));

        try {
            $multipart = MultipartBuilder::make()->file('big', $tmpFile, 'big.bin');

            $before = memory_get_usage(true);
            $built = $multipart->buildToStream();
            $after = memory_get_usage(true);

            self::assertLessThan(
                4 * 1024 * 1024,
                $after - $before,
                'Upload deve usar buffer com spill em disco, não materializar o arquivo em memória.'
            );
            self::assertSame($built['content_length'], (int)ftell($built['stream']));
            self::assertGreaterThan(5 * 1024 * 1024, $built['content_length']);

            fclose($built['stream']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
