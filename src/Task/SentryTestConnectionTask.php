<?php

/**
 * Class: SentryTestConnectionTask.
 *
 * @author  Matías Halles 2019-2021 <matias.halles@gmail.com>
 * @author  Russell Michell 2021 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Tasks;

use Monolog\Level;
use PhpTek\Sentry\Adaptor\SentryAdaptor;
use PhpTek\Sentry\Handler\SentryHandler;
use PhpTek\Sentry\Helper\SentryTracingHelper;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Tests a connection to Sentry, without having to hack directly inside
 * the parent app or site.
 *
 * See more examples in docs/en/usage.md.
 */
class SentryTestConnectionTask extends BuildTask
{
    /**
     * @var string
     */
    protected string $title = 'Test Sentry Configuration';

    /**
     * @var string
     */
    protected static string $description = 'Captures message for all levels available';

    /**
     * Log test message for all log levels into Sentry
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        /** @var LoggerInterface $logger */
        $logger = Injector::inst()->createWithArgs(LoggerInterface::class, ['error-log'])
            ->pushHandler(SentryHandler::create());

        SentryTracingHelper::withTransaction('sentry.test-connection', function () use ($logger, $output): void {
            foreach (Level::NAMES as $name) {
                $func = strtolower($name);
                $logger->$func(sprintf('Testing Severity Level: %s', $name));

                $output->writeln(sprintf('Tested Severity Level: %s', $name));
            }
        }, 'task.sentry');

        SentryAdaptor::flush();

        $output->writeln("Done!");

        return 0; // success
    }
}
