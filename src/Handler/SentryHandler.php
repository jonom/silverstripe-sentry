<?php

/**
 * Class: SentryHandler.
 *
 * @author  Russell Michell 2017-2021 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Handler;

use Sentry\Client;
use Sentry\ClientInterface;
use Throwable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sentry\Severity;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\ClientBuilder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use PhpTek\Sentry\Log\SentryLogger;
use PhpTek\Sentry\Adaptor\SentryAdaptor;
use PhpTek\Sentry\Adaptor\SentrySeverity;

/**
 * Monolog handler to send messages to a Sentry (https://github.com/getsentry/sentry) server
 * using sentry-php (https://github.com/getsentry/sentry-php).
 */
class SentryHandler extends AbstractProcessingHandler
{

    use Injectable,
        Configurable;

    /**
     * @var int|null
     */
    private static ?int $log_level = null;

    /**
     * @var SentryLogger|null
     */
    private $logger = null;

    /**
     * @var ClientInterface|Client|null
     */
    private $client = null;

    /**
     * @param int|null $level
     * @param bool $bubble
     * @param array $config
     */
    public function __construct(?int $level = null, bool $bubble = true, array $config = [])
    {
        $client = ClientBuilder::create(SentryAdaptor::get_opts() ?: [])->getClient();
        $level = $level ?: $this->config()->get('log_level');
        $level = Level::fromName($level ?? Level::Debug->getName());

        SentrySdk::setCurrentHub(new Hub($client));

        $config['level'] = $level;

        $this->logger = SentryLogger::factory($client, $config);
        $this->client = $client;

        parent::__construct($level, $bubble);
    }

    /**
     * write() forms the entry point into the physical sending of the error. The
     * sending itself is done by the current adaptor's `send()` method.
     *
     * @param  array $record An array of error-context metadata with the following
     *                       available keys:
     *
     *                       - message
     *                       - context
     *                       - level
     *                       - level_name
     *                       - channel
     *                       - datetime
     *                       - extra
     *                       - formatted
     *
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        $recordData = array_merge($record->toArray(), [
            'timestamp' => $record->datetime->getTimestamp(),
        ]);
        $isException = self::isExceptionRecord($recordData);
        $adaptor = $this->logger->getAdaptor();

        // For reasons..this is the only spot where we're able to getCurrentUser()
        $member = Security::getCurrentUser() ?: null;
        $adaptor->setContext('user', SentryLogger::user_data($member));

        $scope = $adaptor->getContext(
            extra: self::buildRecordExtra($recordData),
            level: $recordData['level_name'] ?? null
        );

        // Create a Sentry EventHint and pass an instance of Stacktrace to it.
        // See SentryAdaptor: We explicitly enable/disable default (Sentry) stacktraces.
        $eventHint = null;

        if (Config::inst()->get(static::class, 'custom_stacktrace')) {
            $eventHint = EventHint::fromArray([
                'stacktrace' => new Stacktrace(SentryLogger::backtrace($recordData)),
            ]);
        }

        if ($isException) {
            $this->client->captureException(
                $recordData['context']['exception'],
                $scope,
                $eventHint
            );
        } else {
            $this->client->captureMessage(
                $recordData['message'],
                new Severity(SentrySeverity::process_severity($recordData['level_name'])),
                $scope,
                $eventHint
            );
        }
    }

    /**
     * @param array $recordData
     * @return bool
     */
    private static function isExceptionRecord(array $recordData): bool
    {
        return isset($recordData['context']['exception'])
            && $recordData['context']['exception'] instanceof Throwable;
    }

    /**
     * Build extra payload from Monolog record context and metadata.
     *
     * @param array $recordData
     * @return array
     */
    private static function buildRecordExtra(array $recordData): array
    {
        $recordExtra = array_merge(
            $recordData['extra'] ?? [],
            $recordData['context'] ?? []
        );

        unset($recordExtra['exception']);

        $recordExtra['monolog_channel'] = $recordData['channel'] ?? null;
        $recordExtra['monolog_level_name'] = $recordData['level_name'] ?? null;
        $recordExtra['monolog_level'] = $recordData['level'] ?? null;
        $recordExtra['monolog_timestamp'] = $recordData['timestamp'] ?? null;

        return array_filter($recordExtra, static function ($value): bool {
            return $value !== null;
        });
    }
}
