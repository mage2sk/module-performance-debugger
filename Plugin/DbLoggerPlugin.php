<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\DB\LoggerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Hooks Magento\Framework\DB\LoggerInterface to record per-query timing.
 *
 * Magento calls startTimer() before each adapter operation and logStats() after.
 * We capture both to compute duration without disrupting the underlying logger.
 *
 * SQL is fingerprinted (numbers/strings stripped) to detect duplicate / N+1
 * patterns even when bound parameters differ.
 */
class DbLoggerPlugin
{
    private float $timer = 0.0;

    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function afterStartTimer(LoggerInterface $subject, $result): void
    {
        if (!$this->profiler->isActive() || !$this->config->trackDb()) {
            return;
        }
        $this->timer = microtime(true);
    }

    public function afterLogStats(LoggerInterface $subject, $result, $type, $sql, $bind = [], $statement = null): void
    {
        if (!$this->profiler->isActive() || !$this->config->trackDb() || $this->timer === 0.0) {
            return;
        }
        $duration = (microtime(true) - $this->timer) * 1000.0;
        $this->timer = 0.0;

        if ($type !== LoggerInterface::TYPE_QUERY) {
            $this->profiler->record('query', strtoupper((string) $type) . ' ' . $sql, $duration);
            return;
        }
        $sqlText = (string) $sql;
        $fingerprint = $this->fingerprint($sqlText);
        $callsite = $this->captureCallsite();
        $this->profiler->record(
            'query',
            $this->snippet($sqlText),
            $duration,
            [
                'fingerprint' => $fingerprint,
                'bind' => $this->safeBind($bind),
                'callsite' => $callsite,
            ],
            $callsite['summary'] ?? null
        );
    }

    /**
     * Capture the first user-code frame above the DB layer.
     *
     * Skips Zend_Db / Magento DB framework / interceptor / closure frames so the
     * caller-of-interest (the actual ResourceModel or Repository) bubbles to the
     * top. Returns an array with summary (file:line) plus a short trail.
     */
    private function captureCallsite(): array
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        $skipPrefixes = [
            'Zend_Db',
            'Magento\\Framework\\DB\\',
            'Magento\\Framework\\Model\\ResourceModel\\Db\\AbstractDb',
            'Magento\\Framework\\Interception\\',
            'Magento\\Framework\\ObjectManager\\',
            'Magento\\Framework\\Profiler',
            'Magento\\Framework\\App\\ResourceConnection',
            'Panth\\PerformanceDebugger\\Plugin\\DbLoggerPlugin',
        ];
        $trail = [];
        $first = null;
        foreach ($bt as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
            if ($function === '{closure}' || str_contains($class, '\\Interceptor')) {
                continue;
            }
            $skip = false;
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $callable = $class !== ''
                ? $class . ($frame['type'] ?? '::') . $function
                : $function;
            $rel = $this->relPath($file);
            $entry = ['callable' => $callable, 'file' => $rel, 'line' => $line];
            if ($first === null && $rel !== '') {
                $first = $entry;
            }
            $trail[] = $entry;
            if (count($trail) >= 5) {
                break;
            }
        }
        $first = $first ?? ($trail[0] ?? null);
        return [
            'summary' => $first ? ($first['callable'] . ' (' . $first['file'] . ':' . $first['line'] . ')') : '',
            'trail' => $trail,
        ];
    }

    private function relPath(string $abs): string
    {
        if ($abs === '') {
            return '';
        }
        foreach (['/var/www/html/', getcwd() . '/'] as $root) {
            if (str_starts_with($abs, $root)) {
                return substr($abs, strlen($root));
            }
        }
        return $abs;
    }

    private function fingerprint(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/"[^"]*"/', '?', $sql);
        $sql = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', 'IN (?)', $sql);
        return (string) $sql;
    }

    private function snippet(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return strlen($sql) > 480 ? substr($sql, 0, 477) . '...' : $sql;
    }

    private function safeBind(array $bind): array
    {
        $out = [];
        foreach ($bind as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[(string) $k] = is_string($v) && strlen($v) > 80 ? substr($v, 0, 77) . '...' : $v;
            } else {
                $out[(string) $k] = '[' . gettype($v) . ']';
            }
        }
        return $out;
    }
}
