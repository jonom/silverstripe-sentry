<?php

/**
 * Class: SentryLogsHandler.
 *
 * @author  Russell Michell 2017-2024 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Optional Monolog handler for Sentry Logs (distinct from Sentry Error Monitoring).
 */
class SentryLogsHandler extends AbstractProcessingHandler
{
    use Injectable;
    use Configurable;

    /**
     * @var string
     */
    private static string $log_level = 'INFO';

    /**
     * @var bool
     */
    private static bool $enabled = false;

    /**
     * When false, records at-or-above SentryHandler's threshold are skipped to
     * avoid duplication between Error Monitoring and Logs.
     *
     * @var bool
     */
    private static bool $mirror_error_levels = false;

    /**
     * @var object|null
     */
    private ?object $logsHandler = null;

    /**
     * @param int|null $level
     * @param bool $bubble
     */
    public function __construct(?int $level = null, bool $bubble = true)
    {
        $configuredLevel = $level ?: $this->config()->get('log_level');
        $monologLevel = self::normaliseLevel($configuredLevel, Level::Info);

        parent::__construct($monologLevel, $bubble);
    }

    /**
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (!(bool) $this->config()->get('enabled')) {
            return;
        }

        if (!(bool) $this->config()->get('mirror_error_levels')
            && self::isAtOrAboveErrorMonitoringThreshold($record->level)
        ) {
            return;
        }

        $this->forwardToSentryLogs($record);
    }

    /**
     * @param LogRecord $record
     * @return void
     */
    private function forwardToSentryLogs(LogRecord $record): void
    {
        // SDK >=4.15
        if (class_exists('\\Sentry\\Monolog\\LogsHandler')) {
            if ($this->logsHandler === null) {
                $handlerClass = '\\Sentry\\Monolog\\LogsHandler';
                $this->logsHandler = new $handlerClass();
            }

            $this->logsHandler->handle($record);

            return;
        }

        // SDK >=4.12 fallback when logs API exists but Monolog logs handler does not.
        if (function_exists('Sentry\\logger')) {
            $logger = \Sentry\logger();
            $method = strtolower($record->level->getName());

            if (is_object($logger) && method_exists($logger, $method)) {
                $logger->{$method}($record->message, array_merge($record->extra, $record->context));
            }
        }
    }

    /**
     * @param Level $level
     * @return bool
     */
    private static function isAtOrAboveErrorMonitoringThreshold(Level $level): bool
    {
        $configuredThreshold = static::config()->get(SentryHandler::class, 'log_level');
        $threshold = self::normaliseLevel($configuredThreshold, Level::Warning);

        return $level->value >= $threshold->value;
    }

    /**
     * @param mixed $rawLevel
     * @param Level $fallback
     * @return Level
     */
    private static function normaliseLevel($rawLevel, Level $fallback): Level
    {
        if ($rawLevel instanceof Level) {
            return $rawLevel;
        }

        if (is_int($rawLevel)) {
            return Level::from($rawLevel);
        }

        if (is_string($rawLevel) && $rawLevel !== '') {
            return Level::fromName(strtoupper($rawLevel));
        }

        return $fallback;
    }
}
