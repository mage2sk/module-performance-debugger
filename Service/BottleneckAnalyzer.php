<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Service;

use Panth\PerformanceDebugger\Helper\Config;

class BottleneckAnalyzer
{
    private const SAVINGS_FACTOR_QUERY = 0.85;
    private const SAVINGS_FACTOR_BLOCK = 0.70;
    private const SAVINGS_FACTOR_OBSERVER = 0.60;
    private const SAVINGS_FACTOR_PLUGIN = 0.50;
    private const SAVINGS_FACTOR_LAYOUT = 0.40;

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function analyze(Profiler $profiler): array
    {
        $events = $profiler->getEvents();
        $findings = [];
        $id = 0;

        $queryGroups = $this->groupQueries($events);
        $dupeThreshold = $this->config->duplicateQueryThreshold();
        $slowQueryMs = $this->config->slowQueryMs();

        foreach ($queryGroups as $fp => $group) {
            $count = $group['count'];
            $totalMs = $group['total_ms'];
            $sampleSql = $group['sample'];

            if ($count >= $dupeThreshold) {
                $isLikelyN1 = $this->looksLikeN1($sampleSql);
                $severity = $isLikelyN1 ? 'critical' : ($count >= $dupeThreshold * 3 ? 'high' : 'medium');
                $callsites = $this->aggregateCallsites($group['callsites']);
                $stats = $this->timingStats($group['durations']);
                $module = $this->moduleFromCallsite($callsites[0] ?? null);
                $findings[] = [
                    'id' => ++$id,
                    'kind' => 'duplicate_query',
                    'severity' => $severity,
                    'title' => sprintf('%s repeated %d× (%.1f ms total)', $isLikelyN1 ? 'N+1 query' : 'Duplicate query', $count, $totalMs),
                    'source' => $sampleSql,
                    'measured_ms' => round($totalMs, 2),
                    'module' => $module,
                    'why' => $isLikelyN1
                        ? 'Same SQL fingerprint executed many times in one request - classic N+1. Each round-trip adds latency.'
                        : 'Identical query repeated more than threshold. Likely missing static cache inside the request scope.',
                    'suggestion' => $isLikelyN1
                        ? 'Batch via WHERE id IN (...) or a single JOIN, or cache the resolved set in a request-scoped registry.'
                        : 'Memoize the result in a request-scoped service (private static or Magento\\Framework\\Registry).',
                    'estimated_savings_ms' => round($totalMs * self::SAVINGS_FACTOR_QUERY, 2),
                    'invocations' => $count,
                    'callsites' => $callsites,
                    'timing' => $stats,
                    'binds' => $this->sampleBinds($group['binds']),
                ];
            }

            foreach ($group['slow_events'] as $slowEvent) {
                $slowMs = (float) $slowEvent['duration'];
                if ($slowMs >= $slowQueryMs) {
                    $callsite = $slowEvent['meta']['callsite'] ?? null;
                    $callsites = $callsite && !empty($callsite['summary'])
                        ? [['summary' => $callsite['summary'], 'count' => 1, 'trail' => $callsite['trail'] ?? []]]
                        : [];
                    $findings[] = [
                        'id' => ++$id,
                        'kind' => 'slow_query',
                        'severity' => $this->severityFromMs($slowMs, $slowQueryMs),
                        'title' => sprintf('Slow query (%.1f ms)', $slowMs),
                        'source' => $sampleSql,
                        'measured_ms' => round($slowMs, 2),
                        'module' => $this->moduleFromCallsite($callsites[0] ?? null),
                        'why' => 'Single query exceeded the slow-query threshold. Often a missing index or unbounded scan.',
                        'suggestion' => 'EXPLAIN the query, add an index on the WHERE/JOIN columns, or add LIMIT and pagination.',
                        'estimated_savings_ms' => round($slowMs * self::SAVINGS_FACTOR_QUERY, 2),
                        'invocations' => 1,
                        'callsites' => $callsites,
                    ];
                }
            }
        }

        $slowBlockMs = $this->config->slowBlockMs();
        foreach ($events as $e) {
            if ($e['kind'] === 'block' && $e['duration'] >= $slowBlockMs) {
                $module = $this->moduleFromEvent($e);
                $findings[] = [
                    'id' => ++$id,
                    'kind' => 'slow_block',
                    'severity' => $this->severityFromMs($e['duration'], $slowBlockMs),
                    'title' => sprintf('Slow block render (%.1f ms): %s', $e['duration'], $e['label']),
                    'source' => $e['source'] ?? ($e['meta']['template'] ?? $e['meta']['class'] ?? ''),
                    'measured_ms' => round($e['duration'], 2),
                    'module' => $module,
                    'why' => 'A single block exceeded the slow render threshold. Likely heavy DB or computation inside toHtml.',
                    'suggestion' => 'Enable block_html cache for this block, move work to a ViewModel that batches queries, or lazy-render via AJAX.',
                    'estimated_savings_ms' => round($e['duration'] * self::SAVINGS_FACTOR_BLOCK, 2),
                ];
            }
        }

        $slowObserverMs = $this->config->slowObserverMs();
        foreach ($events as $e) {
            if ($e['kind'] === 'observer' && $e['duration'] >= $slowObserverMs) {
                $findings[] = [
                    'id' => ++$id,
                    'kind' => 'slow_observer',
                    'severity' => $this->severityFromMs($e['duration'], $slowObserverMs),
                    'title' => sprintf('Slow observer (%.1f ms): %s', $e['duration'], $e['label']),
                    'source' => $e['label'],
                    'measured_ms' => round($e['duration'], 2),
                    'module' => $this->moduleFromEvent($e),
                    'why' => 'Synchronous observer added measurable latency to dispatch. Heavy observers stall the request.',
                    'suggestion' => 'Move work to a queue consumer (async), reduce side-effect scope, or guard with an early-return on the relevant condition.',
                    'estimated_savings_ms' => round($e['duration'] * self::SAVINGS_FACTOR_OBSERVER, 2),
                ];
            }
        }

        $heavyModules = $this->heaviestModules($events, 5);
        foreach ($heavyModules as $module => $ms) {
            if ($ms >= 100) {
                $findings[] = [
                    'id' => ++$id,
                    'kind' => 'heavy_module',
                    'severity' => $ms >= 400 ? 'high' : 'medium',
                    'title' => sprintf('Heavy module: %s (%.1f ms)', $module, $ms),
                    'source' => $module,
                    'measured_ms' => round($ms, 2),
                    'module' => $module,
                    'why' => 'This module accounts for a large share of the request time across blocks/observers/queries.',
                    'suggestion' => 'Audit its blocks for cacheability, its observers for sync work that can be queued, and its repositories for N+1 patterns.',
                    'estimated_savings_ms' => round($ms * 0.40, 2),
                ];
            }
        }

        foreach ($findings as &$f) {
            $f['is_core'] = $this->isCoreFinding($f);
            $userFrame = $this->userlandFrame($f['callsites'] ?? []);
            if ($userFrame !== null) {
                $f['userland_frame'] = $userFrame;
            }
        }
        unset($f);

        usort($findings, function ($a, $b) {
            return ($a['is_core'] ? 1 : 0) <=> ($b['is_core'] ? 1 : 0)
                ?: $this->severityWeight($b['severity']) <=> $this->severityWeight($a['severity'])
                ?: $b['estimated_savings_ms'] <=> $a['estimated_savings_ms'];
        });

        return $findings;
    }

    public function severityWeight(string $sev): int
    {
        return match ($sev) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    public function totalEstimatedSavings(array $findings): float
    {
        return (float) array_sum(array_column($findings, 'estimated_savings_ms'));
    }

    private function severityFromMs(float $ms, float $threshold): string
    {
        if ($ms >= $threshold * 8) {
            return 'critical';
        }
        if ($ms >= $threshold * 4) {
            return 'high';
        }
        if ($ms >= $threshold * 2) {
            return 'medium';
        }
        return 'low';
    }

    private function looksLikeN1(string $sql): bool
    {
        $sql = strtoupper($sql);
        if (!str_contains($sql, 'SELECT')) {
            return false;
        }
        return (bool) preg_match('/WHERE\s+\S+\s*=\s*\?/', $sql)
            && !str_contains($sql, ' IN ');
    }

    private function groupQueries(array $events): array
    {
        $groups = [];
        foreach ($events as $e) {
            if ($e['kind'] !== 'query') {
                continue;
            }
            $fp = $e['meta']['fingerprint'] ?? $e['label'];
            if (!isset($groups[$fp])) {
                $groups[$fp] = [
                    'count' => 0,
                    'total_ms' => 0.0,
                    'sample' => $e['label'],
                    'slow_events' => [],
                    'callsites' => [],
                    'durations' => [],
                    'binds' => [],
                ];
            }
            $groups[$fp]['count']++;
            $groups[$fp]['total_ms'] += $e['duration'];
            $groups[$fp]['durations'][] = $e['duration'];
            $groups[$fp]['slow_events'][] = $e;
            if (!empty($e['meta']['callsite']['summary'])) {
                $groups[$fp]['callsites'][] = $e['meta']['callsite'];
            }
            if (!empty($e['meta']['bind']) && is_array($e['meta']['bind'])) {
                $groups[$fp]['binds'][] = $e['meta']['bind'];
            }
        }
        return $groups;
    }

    private function timingStats(array $durations): array
    {
        if (empty($durations)) {
            return ['min' => 0.0, 'avg' => 0.0, 'max' => 0.0];
        }
        $count = count($durations);
        return [
            'min' => round((float) min($durations), 3),
            'avg' => round(array_sum($durations) / $count, 3),
            'max' => round((float) max($durations), 3),
        ];
    }

    private function sampleBinds(array $bindList): array
    {
        if (empty($bindList)) {
            return [];
        }
        $byKey = [];
        foreach ($bindList as $bind) {
            foreach ($bind as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    continue;
                }
                $key = (string) $k;
                $byKey[$key] = $byKey[$key] ?? [];
                $byKey[$key][] = (string) ($v ?? 'null');
            }
        }
        $out = [];
        foreach ($byKey as $key => $values) {
            $unique = array_values(array_unique($values));
            if (count($unique) <= 1) {
                continue;
            }
            $out[] = [
                'name' => $key,
                'distinct' => count($unique),
                'sample' => array_slice($unique, 0, 8),
                'total' => count($values),
            ];
        }
        usort($out, fn($a, $b) => $b['distinct'] <=> $a['distinct']);
        return array_slice($out, 0, 4);
    }

    private function moduleFromCallsite(?array $callsite): ?string
    {
        if (!$callsite) {
            return null;
        }
        $callable = $callsite['summary'] ?? '';
        if ($callable !== '' && preg_match('#^([A-Z][a-zA-Z0-9]+)\\\\([A-Z][a-zA-Z0-9]+)#', $callable, $m)) {
            return $m[1] . '_' . $m[2];
        }
        $trail = $callsite['trail'] ?? [];
        foreach ($trail as $f) {
            $file = (string) ($f['file'] ?? '');
            if (preg_match('#vendor/[^/]+/(?:module-)?([a-z0-9-]+)#', $file, $m)) {
                $name = str_replace('-', ' ', $m[1]);
                return ucwords($name);
            }
            if (preg_match('#app/code/([A-Z][a-zA-Z0-9]+)/([A-Z][a-zA-Z0-9]+)#', $file, $m)) {
                return $m[1] . '_' . $m[2];
            }
        }
        return null;
    }

    public function userlandFrame(array $callsites): ?array
    {
        foreach ($callsites as $cs) {
            foreach (($cs['trail'] ?? []) as $frame) {
                if ($this->isUserlandFile((string) ($frame['file'] ?? ''))) {
                    return $frame;
                }
            }
        }
        return null;
    }

    public function isCoreFinding(array $finding): bool
    {
        $module = (string) ($finding['module'] ?? '');
        if ($finding['kind'] === 'duplicate_query' || $finding['kind'] === 'slow_query') {
            $userFrame = $this->userlandFrame($finding['callsites'] ?? []);
            return $userFrame === null;
        }
        if ($finding['kind'] === 'slow_block') {
            $source = (string) ($finding['source'] ?? '');
            if (str_starts_with($source, 'app/design/') || str_contains($source, '/app/design/')) {
                return false;
            }

            if (preg_match('#^([A-Z][a-zA-Z0-9]+)_([A-Z][a-zA-Z0-9]+)::#', $source, $m)) {
                return $m[1] === 'Magento';
            }

            return str_starts_with($module, 'Magento_') || str_starts_with($source, 'Magento\\');
        }
        if ($finding['kind'] === 'slow_observer') {
            return false;
        }
        return str_starts_with($module, 'Magento_');
    }

    private function isUserlandFile(string $file): bool
    {
        if ($file === '') {
            return false;
        }
        if (str_starts_with($file, 'app/code/')) {
            return true;
        }
        if (str_starts_with($file, 'app/design/')) {
            return true;
        }
        if (preg_match('#^vendor/([^/]+)/#', $file, $m)) {
            return $m[1] !== 'magento';
        }
        return false;
    }

    private function aggregateCallsites(array $callsites): array
    {
        $bins = [];
        foreach ($callsites as $cs) {
            $key = $cs['summary'] ?? '';
            if ($key === '') {
                continue;
            }
            if (!isset($bins[$key])) {
                $bins[$key] = ['summary' => $key, 'count' => 0, 'trail' => $cs['trail'] ?? []];
            }
            $bins[$key]['count']++;
        }
        usort($bins, fn($a, $b) => $b['count'] <=> $a['count']);
        return array_values(array_slice($bins, 0, 5));
    }

    private function heaviestModules(array $events, int $limit): array
    {
        $bins = [];
        foreach ($events as $e) {
            $module = $this->moduleFromEvent($e);
            if ($module === null) {
                continue;
            }
            $bins[$module] = ($bins[$module] ?? 0.0) + $e['duration'];
        }
        arsort($bins);
        return array_slice($bins, 0, $limit, true);
    }

    private function moduleFromEvent(array $e): ?string
    {
        $candidate = $e['source'] ?? null;
        if (!$candidate) {
            $candidate = $e['meta']['class'] ?? $e['label'] ?? null;
        }
        if (!$candidate) {
            return null;
        }
        $candidate = (string) $candidate;
        if (preg_match('#^([A-Z][a-zA-Z0-9]+)_([A-Z][a-zA-Z0-9]+)::#', $candidate, $m)) {
            return $m[1] . '_' . $m[2];
        }
        if (preg_match('#^([A-Z][a-zA-Z0-9]+)[\\\\/]([A-Z][a-zA-Z0-9]+)#', $candidate, $m)) {
            return $m[1] . '_' . $m[2];
        }
        return null;
    }
}
