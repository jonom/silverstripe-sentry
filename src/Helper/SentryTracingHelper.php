<?php

/**
 * Class: SentryTracingHelper.
 *
 * @author  Russell Michell 2017-2024 <russ@theruss.com>
 * @package phptek/sentry
 */

namespace PhpTek\Sentry\Helper;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Throwable;

/**
 * Small tracing helpers for instrumenting request/task code paths.
 */
class SentryTracingHelper
{
    /**
     * Wrap a callback in a Sentry transaction, restoring prior span afterwards.
     *
     * @param string $name
     * @param callable $callback
     * @param string $op
     * @return mixed
     */
    public static function withTransaction(string $name, callable $callback, string $op = 'app.request')
    {
        $hub = SentrySdk::getCurrentHub();
        $context = (new TransactionContext())
            ->setName($name)
            ->setOp($op);

        $transaction = $hub->startTransaction($context);
        $previousSpan = $hub->getSpan();
        $hub->setSpan($transaction);

        try {
            $result = $callback($transaction);
            $transaction->setStatus(SpanStatus::ok());

            return $result;
        } catch (Throwable $e) {
            $transaction->setStatus(SpanStatus::internalError());
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan($previousSpan);
        }
    }
}
