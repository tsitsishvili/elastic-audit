<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Fiber;
use Swoole\Coroutine;
use Throwable;

final class ExecutionContextId
{
    public static function current(): string
    {
        try {
            if (class_exists('Swoole\\Coroutine')) {
                /** @var int $coroutineId */
                $coroutineId = Coroutine::getCid();

                if ($coroutineId >= 0) {
                    return 'swoole:'.$coroutineId;
                }
            }
        } catch (Throwable) {
            // Fall back to Fiber/main execution identity.
        }

        $fiber = Fiber::getCurrent();

        return $fiber === null ? 'main' : 'fiber:'.spl_object_id($fiber);
    }
}
