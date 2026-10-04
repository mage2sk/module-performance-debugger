<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Service;

class Redactor
{
    private const SENSITIVE_PARAM_PATTERN =
        '/pass|pwd|token|secret|key|code|hash|session|sid|auth|email|signature|nonce|panth_perf/i';
    private const SENSITIVE_PATH_PATTERN = '#/(key|form_key|token|password|secret|hash|code|email)/[^/?\#]+#i';
    private const PLACEHOLDER_PATTERN = '/^\[(?:string:\d+|[a-z]+)\]$/';

    public function sanitizeUrl(string $url): string
    {
        $url = (string) preg_replace(self::SENSITIVE_PATH_PATTERN, '/$1/***', $url);
        $queryPos = strpos($url, '?');
        if ($queryPos === false) {
            return $url;
        }
        $fragmentPos = strpos($url, '#', $queryPos);
        $query = $fragmentPos === false
            ? substr($url, $queryPos + 1)
            : substr($url, $queryPos + 1, $fragmentPos - $queryPos - 1);
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = urldecode(substr($pair, 0, $eq));
            if (preg_match(self::SENSITIVE_PARAM_PATTERN, $name)) {
                $pairs[$i] = substr($pair, 0, $eq) . '=***';
            }
        }
        $fragment = $fragmentPos === false ? '' : substr($url, $fragmentPos);

        return substr($url, 0, $queryPos + 1) . implode('&', $pairs) . $fragment;
    }

    public function redactSql(string $sql): string
    {
        $redacted = preg_replace(
            ["/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s'],
            ["'?'", '"?"'],
            $sql
        );

        return is_string($redacted) ? $redacted : '';
    }

    public function safeBind(array $bind): array
    {
        $out = [];
        foreach ($bind as $k => $v) {
            $out[(string) $k] = $this->safeValue($v);
        }

        return $out;
    }

    public function redactValues(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->redactValues($v);
            }

            return $out;
        }

        return $this->safeValue($value);
    }

    private function safeValue(mixed $v): mixed
    {
        if ($v === null || is_int($v) || is_float($v) || is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            if (preg_match('/^-?\d{1,10}$/', $v) || preg_match(self::PLACEHOLDER_PATTERN, $v)) {
                return $v;
            }

            return '[string:' . strlen($v) . ']';
        }

        return '[' . gettype($v) . ']';
    }
}
