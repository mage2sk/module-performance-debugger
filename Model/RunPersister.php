<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\BottleneckAnalyzer;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Writes a completed profiler run + its events to panth_perf_run / panth_perf_run_event.
 *
 * Uses raw connection insert/insertMultiple instead of the AbstractModel save loop
 * to keep the per-event overhead minimal — a profiled page can produce thousands
 * of events.
 */
class RunPersister
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly BottleneckAnalyzer $analyzer,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function persist(Profiler $profiler): ?int
    {
        $events = $profiler->getEvents();
        if ($events === []) {
            return null;
        }
        $context = $profiler->getRequestContext();
        $aggregates = $profiler->getAggregates();
        $findings = $this->analyzer->analyze($profiler);
        $maxSeverity = 0;
        foreach ($findings as $f) {
            $maxSeverity = max($maxSeverity, $this->analyzer->severityWeight($f['severity']));
        }

        $connection = $this->resource->getConnection();
        $runTable = $this->resource->getTableName('panth_perf_run');
        $eventTable = $this->resource->getTableName('panth_perf_run_event');

        $connection->insert($runTable, [
            'token' => $context['token'],
            'url' => $context['url'],
            'route' => substr($context['route'], 0, 255),
            'method' => $context['method'],
            'status_code' => 200,
            'area' => 'frontend',
            'store_id' => $context['store_id'],
            'total_time' => round($profiler->totalElapsedMs(), 3),
            'db_time' => round($aggregates['query']['time'] ?? 0.0, 3),
            'db_queries' => (int) ($aggregates['query']['count'] ?? 0),
            'db_slow' => (int) ($aggregates['query']['slow'] ?? 0),
            'db_duplicates' => count($profiler->getDuplicateQueries()),
            'block_time' => round($aggregates['block']['time'] ?? 0.0, 3),
            'block_count' => (int) ($aggregates['block']['count'] ?? 0),
            'observer_time' => round($aggregates['observer']['time'] ?? 0.0, 3),
            'observer_count' => (int) ($aggregates['observer']['count'] ?? 0),
            'plugin_time' => round($aggregates['plugin']['time'] ?? 0.0, 3),
            'plugin_count' => (int) ($aggregates['plugin']['count'] ?? 0),
            'memory_peak' => $context['memory_peak'],
            'bottleneck_count' => count($findings),
            'severity_max' => $maxSeverity,
            'summary' => $this->serializer->serialize([
                'aggregates' => $aggregates,
                'findings' => $findings,
                'duplicates' => $profiler->getDuplicateQueries(),
            ]),
        ]);
        $runId = (int) $connection->lastInsertId($runTable);

        $rows = [];
        foreach ($events as $e) {
            $rows[] = [
                'run_id' => $runId,
                'kind' => substr((string) $e['kind'], 0, 32),
                'label' => substr((string) $e['label'], 0, 500),
                'source' => $e['source'] !== null ? substr((string) $e['source'], 0, 500) : null,
                'duration' => $e['duration'],
                'memory_delta' => (int) ($e['memory'] ?? 0),
                'invocations' => (int) ($e['invocations'] ?? 1),
                'severity' => $e['severity'] !== null ? substr((string) $e['severity'], 0, 16) : null,
                'meta' => !empty($e['meta']) ? $this->serializer->serialize($e['meta']) : null,
            ];
            if (count($rows) >= 500) {
                $connection->insertMultiple($eventTable, $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $connection->insertMultiple($eventTable, $rows);
        }
        return $runId;
    }
}
