<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

final class SqlStatementNormalizer
{
    /**
     * Remove comments and literal values without ever substituting bindings.
     */
    public function normalize(string $sql, int $maxBytes = 4096, ?string $driver = null): string
    {
        $sql = $this->sanitize($sql, strtolower($driver ?? 'unknown'));
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        return mb_strcut($sql, 0, max(1, $maxBytes), 'UTF-8');
    }

    public function fingerprint(string $normalizedSql): string
    {
        return hash('sha256', strtolower($normalizedSql));
    }

    public function operation(string $normalizedSql): string
    {
        if (preg_match('/^\s*([a-z]+)/i', $normalizedSql, $matches) !== 1) {
            return 'query';
        }

        return strtolower($matches[1]);
    }

    private function sanitize(string $sql, string $driver): string
    {
        $length              = strlen($sql);
        $normalized          = '';
        $doubleQuotesAreData = ! in_array($driver, ['pgsql', 'oci', 'oracle'], true);
        $hashStartsComment   = in_array($driver, ['mysql', 'mariadb', 'unknown'], true);

        for ($offset = 0; $offset < $length;) {
            $character = $sql[$offset];

            if ($character === "'") {
                $normalized .= '?';
                $offset = $this->quotedEnd($sql, $offset, "'");

                continue;
            }

            if ($character === '"') {
                $end = $this->quotedEnd($sql, $offset, '"', $doubleQuotesAreData);
                $normalized .= $doubleQuotesAreData
                    ? '?'
                    : substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            if ($character === '`') {
                $end = $this->quotedEnd($sql, $offset, '`');
                $normalized .= substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            if ($character === '[' && in_array($driver, ['sqlsrv', 'dblib'], true)) {
                $end = $this->bracketedIdentifierEnd($sql, $offset);
                $normalized .= substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            $dollarQuoteTag = $character === '$' ? $this->dollarQuoteTag($sql, $offset) : null;

            if ($dollarQuoteTag !== null) {
                $tag     = $dollarQuoteTag;
                $closing = strpos($sql, $tag, $offset + strlen($tag));
                $normalized .= '?';
                $offset = $closing === false ? $length : $closing + strlen($tag);

                continue;
            }

            if (substr($sql, $offset, 2) === '/*') {
                $normalized .= ' ';
                $offset = $this->blockCommentEnd($sql, $offset);

                continue;
            }

            if (substr($sql, $offset, 2) === '--' || ($character === '#' && $hashStartsComment)) {
                $normalized .= ' ';
                $lineEnd = strcspn($sql, "\r\n", $offset);
                $offset += $lineEnd;

                continue;
            }

            $number = $this->numberAt($sql, $offset);

            if ($number !== null) {
                $normalized .= '?';
                $offset += strlen($number);

                continue;
            }

            $normalized .= $character;
            $offset++;
        }

        return $normalized;
    }

    private function quotedEnd(
        string $sql,
        int $offset,
        string $quote,
        bool $backslashEscapes = true,
    ): int {
        $length = strlen($sql);

        for ($cursor = $offset + 1; $cursor < $length; $cursor++) {
            if ($backslashEscapes && $sql[$cursor] === '\\') {
                $cursor++;

                continue;
            }

            if ($sql[$cursor] !== $quote) {
                continue;
            }

            if (($sql[$cursor + 1] ?? null) === $quote) {
                $cursor++;

                continue;
            }

            return $cursor + 1;
        }

        return $length;
    }

    private function bracketedIdentifierEnd(string $sql, int $offset): int
    {
        $length = strlen($sql);

        for ($cursor = $offset + 1; $cursor < $length; $cursor++) {
            if ($sql[$cursor] !== ']') {
                continue;
            }

            if (($sql[$cursor + 1] ?? null) === ']') {
                $cursor++;

                continue;
            }

            return $cursor + 1;
        }

        return $length;
    }

    private function dollarQuoteTag(string $sql, int $offset): ?string
    {
        if (preg_match('/\A\$(?:[a-z_][a-z0-9_]*)?\$/i', substr($sql, $offset), $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function blockCommentEnd(string $sql, int $offset): int
    {
        $length = strlen($sql);
        $depth  = 1;

        for ($cursor = $offset + 2; $cursor < $length;) {
            $pair = substr($sql, $cursor, 2);

            if ($pair === '/*') {
                $depth++;
                $cursor += 2;

                continue;
            }

            if ($pair === '*/') {
                $depth--;
                $cursor += 2;

                if ($depth === 0) {
                    return $cursor;
                }

                continue;
            }

            $cursor++;
        }

        return $length;
    }

    private function numberAt(string $sql, int $offset): ?string
    {
        $character = $sql[$offset];
        $previous  = $sql[$offset - 1] ?? null;

        if (! ctype_digit($character)
            && ! ($character === '.' && ctype_digit($sql[$offset + 1] ?? ''))) {
            return null;
        }

        if (is_string($previous) && (ctype_alnum($previous) || $previous === '_')) {
            return null;
        }

        if (preg_match(
            '/\A(?:0x[0-9a-f]+|0b[01]+|(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?)/i',
            substr($sql, $offset),
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
