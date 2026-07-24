<?php

namespace PhpTek\Sentry\Tests;

use PHPUnit\Framework\TestCase;
use PhpTek\Sentry\Adaptor\SentryAdaptor;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\ModulesIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;

/**
 * Exercises SentryAdaptor option normalization behavior.
 */
class SentryAdaptorTest extends TestCase
{
    public function testApplyDefaultIntegrationsWhenUnset(): void
    {
        $opts = SentryAdaptor::applyDefaultIntegrations([]);

        $this->assertArrayHasKey('default_integrations', $opts);
        $this->assertFalse($opts['default_integrations']);

        $this->assertCount(5, $opts['integrations']);
        $this->assertInstanceOf(EnvironmentIntegration::class, $opts['integrations'][0]);
        $this->assertInstanceOf(FrameContextifierIntegration::class, $opts['integrations'][1]);
        $this->assertInstanceOf(ModulesIntegration::class, $opts['integrations'][2]);
        $this->assertInstanceOf(RequestIntegration::class, $opts['integrations'][3]);
        $this->assertInstanceOf(TransactionIntegration::class, $opts['integrations'][4]);
    }

    public function testDoesNotOverrideExplicitIntegrations(): void
    {
        $customIntegrations = ['custom'];

        $opts = SentryAdaptor::applyDefaultIntegrations([
            'integrations' => $customIntegrations,
        ]);

        $this->assertSame($customIntegrations, $opts['integrations']);
        $this->assertArrayNotHasKey('default_integrations', $opts);
    }

    public function testDoesNotOverrideExplicitDefaultIntegrationsFlag(): void
    {
        $opts = SentryAdaptor::applyDefaultIntegrations([
            'default_integrations' => true,
        ]);

        $this->assertTrue($opts['default_integrations']);
        $this->assertArrayNotHasKey('integrations', $opts);
    }
}
