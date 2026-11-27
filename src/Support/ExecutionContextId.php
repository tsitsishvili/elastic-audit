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

    /**
     * False only for a Swoole coroutine id known to have finished; a Fiber's
     * lifetime cannot be looked up from its id, so every other id counts as alive.
     */
    public static function alive(string $id): bool
    {
        if (! str_starts_with($id, 'swoole:')) {
            return true;
        }

        try {
            return ! class_exists('Swoole\\Coroutine') || Coroutine::exists((int) substr($id, 7));
        } catch (Throwable) {
            return true;
        }
    }
}
