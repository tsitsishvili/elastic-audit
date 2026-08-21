<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;

final class AutomaticFunctionProfiler implements FunctionProfiler
{
    private ?FunctionProfiler $active = null;

    public function __construct(
        private readonly FunctionProfiler $excimer,
        private readonly FunctionProfiler $xhprof,
        private readonly string $preferredDriver = 'auto',
    ) {}

    public function available(): bool
    {
        return $this->selected() !== null;
    }

    public function driver(): ?string
    {
        return $this->selected()?->driver();
    }

    public function start(): bool
    {
        if ($this->active !== null) {
            return false;
        }

        $selected = $this->selected();

        if ($selected === null || ! $selected->start()) {
            return false;
        }

        $this->active = $selected;

        return true;
    }

    public function stop(): ?CapturedProfile
    {
        $active       = $this->active;
        $this->active = null;

        return $active?->stop();
    }

    private function selected(): ?FunctionProfiler
    {
        if (($this->preferredDriver === 'auto' || $this->preferredDriver === 'excimer')
            && $this->excimer->available()) {
            return $this->excimer;
        }

        if (($this->preferredDriver === 'auto' || $this->preferredDriver === 'xhprof')
            && $this->xhprof->available()) {
            return $this->xhprof;
        }

        return null;
    }
}
