<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

final class RolloverProfilesIndexCommand extends RolloverIndexCommand
{
    protected $signature = 'elastic-audit:profiles:rollover';

    protected $description = 'Roll over the profiles write alias when lifecycle conditions match.';

    protected string $configKey = 'elastic_audit_metrics.profiles';
}
