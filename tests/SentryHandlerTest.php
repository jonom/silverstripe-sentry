<?php

namespace PhpTek\Sentry\Tests;

use Exception;
use PhpTek\Sentry\Handler\SentryHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Validates record-shaping logic used by SentryHandler prior to capture calls.
 */
class SentryHandlerTest extends TestCase
{
    public function testIsExceptionRecord(): void
    {
        $method = new ReflectionMethod(SentryHandler::class, 'isExceptionRecord');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, [
            'context' => ['exception' => new Exception('boom')],
        ]));

        $this->assertFalse($method->invoke(null, [
            'context' => ['exception' => 'not-an-exception'],
        ]));

        $this->assertFalse($method->invoke(null, [
            'context' => [],
        ]));
    }

    public function testBuildRecordExtraMergesAndSanitisesExceptionData(): void
    {
        $method = new ReflectionMethod(SentryHandler::class, 'buildRecordExtra');
        $method->setAccessible(true);

        $exception = new Exception('nope');
        $result = $method->invoke(null, [
            'channel' => 'app',
            'level_name' => 'ERROR',
            'level' => 400,
            'timestamp' => 1735689600,
            'extra' => [
                'step' => 'bootstrap',
                'line' => 12,
            ],
            'context' => [
                'exception' => $exception,
                'traceHead' => 'abc123',
                'file' => 'MyController.php',
                'line' => 42,
            ],
        ]);

        $this->assertArrayNotHasKey('exception', $result);
        $this->assertSame('abc123', $result['traceHead']);
        $this->assertSame('MyController.php', $result['file']);
        $this->assertSame(42, $result['line']);
        $this->assertSame('bootstrap', $result['step']);

        $this->assertSame('app', $result['monolog_channel']);
        $this->assertSame('ERROR', $result['monolog_level_name']);
        $this->assertSame(400, $result['monolog_level']);
        $this->assertSame(1735689600, $result['monolog_timestamp']);
    }
}
