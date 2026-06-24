<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

/**
 * App-supplied redaction overrides for one surface (headers or body keys).
 *
 * Both lists are matched after the redactor's name normalization (camelCase and
 * kebab-case fold to snake_case).
 */
final readonly class RedactionRules
{
    /**
     * @param string[] $allow Names to NEVER redact, even when a built-in or $block
     *                        rule matches. Matched exactly; takes precedence over everything.
     * @param string[] $block Extra names to ALWAYS redact, in addition to the defaults.
     *                        Matched as whole words in any position, like the built-ins.
     */
    public function __construct(
        public array $allow = [],
        public array $block = [],
    ) {
    }
}
