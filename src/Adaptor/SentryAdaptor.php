<?php

/**
 * Class: SentryAdaptor.
 *
 * @author  Russell Michell 2017-2021 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Adaptor;

use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Severity;
use Sentry\SentrySdk;
use Sentry\Client;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\ModulesIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment as Env;
use PhpTek\Sentry\Adaptor\SentrySeverity;
use PhpTek\Sentry\Helper\SentryHelper;

/**
 * The SentryAdaptor provides a functionality bridge between the getsentry/sentry
 * PHP SDK and {@link SentryLogger} itself.
 */
class SentryAdaptor
{
    /**
     * Internal storage for context. Used only when non-exception
     * data is sent to Sentry instance.
     *
     * @var array
     */
    protected array $context = [
        'env' => '',
        'tags' => [],
        'extra' => [],
        'user' => [],
    ];

    /**
     * @param  Client $client
     * @return void
     */
    public function __construct(Client $client)
    {
        SentrySdk::setCurrentHub(new Hub($client));
    }

    /**
     * Configures Sentry "context" to display additional information about a SilverStripe
     * application's runtime and context.
     *
     * @param  string $field
     * @param  mixed  $data
     * @return mixed null|void
     */
    public function setContext(string $field, $data): SentryAdaptor
    {
        $hub = SentrySdk::getCurrentHub();
        $options = $hub->getClient()->getOptions();

        // Use Sentry's own default stacktrace. This was the default prior to v4
        $options->setAttachStacktrace((bool) !Config::inst()->get(static::class, 'custom_stacktrace'));

        switch ($field) {
            case 'env':
                $options->setEnvironment($data);
                $this->context['env'] = $data;
                break;
            case 'tags':
                $hub->configureScope(function (Scope $scope) use ($data): void {
                    foreach ($data as $tagName => $tagData) {
                        $tagName = SentryHelper::normalise_tag_name($tagName);
                        $scope->setTag($tagName, $tagData);
                        $this->context['tags'][$tagName] = $tagData;
                    }
                });
                break;
            case 'user':
                $hub->configureScope(function (Scope $scope) use ($data): void {
                    $scope->setUser($data);
                    $this->context['user'] = $data;
                });
                break;
            case 'extra':
                $hub->configureScope(function (Scope $scope) use ($data): void {
                    foreach ($data as $extraKey => $extraData) {
                        $extraKey = SentryHelper::normalise_extras_name($extraKey);
                        $scope->setExtra($extraKey, $extraData);
                        $this->context['extra'][$extraKey] = $extraData;
                    }
                });
                break;
            case 'level':
                $hub->configureScope(function (Scope $scope) use ($data): void {
                    $scope->setLevel(new Severity(SentrySeverity::process_severity($data)));
                });
                break;
            default:
                break;
        }

        return $this;
    }

    /**
     * Get _locally_ set contextual data, that we should be able to get from Sentry's
     * current {@link Scope}.
     *
     * Note: This (re) sets data to a new instance of {@link Scope} for passing to
     * captureMessage(). One would expect this to be set by default, as it is for
     * $record data sent to Sentry via captureException(), but it isn't.
     *
     * @param array|null $user
     * @param array $tags
     * @param array $extra
     * @param string|null $level
     * @return Scope
     */
    public function getContext(?array $user = null, array $tags = [], array $extra = [], ?string $level = null): Scope
    {
        $scope = new Scope();

        $userData = $user ?? $this->context['user'];
        $tagsData = array_merge($this->context['tags'] ?? [], $tags);
        $extraData = array_merge($this->context['extra'] ?? [], $extra);

        if (!empty($userData)) {
            $scope->setUser($userData);
        }

        foreach ($tagsData as $tagKey => $tagData) {
            $tagKey = SentryHelper::normalise_tag_name($tagKey);
            $scope->setTag($tagKey, $tagData);
        }

        foreach ($extraData as $extraKey => $extraValue) {
            $extraKey = SentryHelper::normalise_extras_name($extraKey);
            $scope->setExtra($extraKey, $extraValue);
        }

        if ($level !== null) {
            $scope->setLevel(new Severity(SentrySeverity::process_severity($level)));
        }

        return $scope;
    }

    /**
     * Get various userland options to pass to Sentry. Includes detecting and setting
     * proxy options too.
     *
     * @return array
     */
    public static function get_opts(): array
    {
        $opts = [];

        // Extract env-vars from YML config or env
        if ($dsn = Env::getEnv('SENTRY_DSN')) {
            $opts['dsn'] = $dsn;
        }

        if ($release = Env::getEnv('SENTRY_RELEASE_TAG')) {
            $opts['release'] = $release;
        }

        // Env vars take precedence over YML config in array_merge()
        $optsConfig = Config::inst()->get(static::class, 'opts') ?? [];

        $opts = Injector::inst()
            ->convertServiceProperty(array_merge($optsConfig, $opts));

        $opts = self::applyDefaultIntegrations($opts);

        if (!array_key_exists('enable_logs', $opts)
            && Config::inst()->get('PhpTek\\Sentry\\Handler\\SentryLogsHandler', 'enabled')
        ) {
            $opts['enable_logs'] = true;
        }

        // Deal with proxy settings. Sentry permits host:port format but SilverStripe's
        // YML config only permits single backtick-enclosed env/consts per config
        if (!empty($opts['http_proxy'])) {
            if (!empty($opts['http_proxy']['host']) && !empty($opts['http_proxy']['port'])) {
                $opts['http_proxy'] = sprintf(
                    '%s:%s',
                    $opts['http_proxy']['host'],
                    $opts['http_proxy']['port']
                );
            }
        }

        return $opts;
    }

    /**
     * Applies a conservative default integration set to avoid duplicate listener
     * behaviour when no explicit integration configuration is supplied.
     *
     * @param array $opts
     * @return array
     */
    public static function applyDefaultIntegrations(array $opts): array
    {

        // Ref #65: Avoid duplicate exception/error listener execution by
        // explicitly controlling integrations unless users override them.
        if (!array_key_exists('default_integrations', $opts) && !array_key_exists('integrations', $opts)) {
            $opts['default_integrations'] = false;
            $opts['integrations'] = [
                new EnvironmentIntegration(),
                new FrameContextifierIntegration(),
                new ModulesIntegration(),
                new RequestIntegration(),
                new TransactionIntegration(),
            ];
        }

        return $opts;
    }

    /**
     * Flushes any buffered Sentry payloads.
     *
     * @param float $timeout
     * @return void
     */
    public static function flush(float $timeout = 2.0): void
    {
        if (function_exists('Sentry\\flush')) {
            \Sentry\flush($timeout);

            return;
        }

        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client && method_exists($client, 'flush')) {
            $client->flush();
        }
    }
}
