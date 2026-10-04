<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunPersister;
use Panth\PerformanceDebugger\Service\BottleneckAnalyzer;
use Panth\PerformanceDebugger\Service\Profiler;
use PHPUnit\Framework\TestCase;

class RunPersisterTest extends TestCase
{
    private array $inserts = [];
    private array $multiInserts = [];

    private function connection(): AdapterInterface
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('insert')->willReturnCallback(function ($table, array $row) {
            $this->inserts[] = [$table, $row];
            return 1;
        });
        $connection->method('insertMultiple')->willReturnCallback(function ($table, array $rows) {
            $this->multiInserts[] = [$table, $rows];
            return count($rows);
        });
        $connection->method('lastInsertId')->willReturn('77');

        return $connection;
    }

    private function persister(?AdapterInterface $connection = null, string|\Throwable $area = 'frontend'): RunPersister
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection ?? $this->connection());
        $resource->method('getTableName')->willReturnCallback(static fn($t) => 'pfx_' . $t);

        $config = $this->createStub(Config::class);
        $config->method('duplicateQueryThreshold')->willReturn(2);
        $config->method('slowQueryMs')->willReturn(50.0);
        $config->method('slowBlockMs')->willReturn(50.0);
        $config->method('slowObserverMs')->willReturn(30.0);

        $state = $this->createStub(AppState::class);
        if ($area instanceof \Throwable) {
            $state->method('getAreaCode')->willThrowException($area);
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }

        return new RunPersister($resource, $config, new BottleneckAnalyzer($config), new Json(), $state);
    }

    private function profiler(array $events, string $route = 'cms/index/index'): Profiler
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('getEvents')->willReturn($events);
        $profiler->method('getRequestContext')->willReturn([
            'token' => 'tok',
            'url' => 'https://shop.test/',
            'route' => $route,
            'method' => 'GET',
            'store_id' => 1,
            'memory_peak' => 2048,
        ]);
        $profiler->method('getAggregates')->willReturn([
            'query' => ['time' => 12.34567, 'count' => 2, 'slow' => 1],
            'block' => ['time' => 3.0, 'count' => 1],
        ]);
        $profiler->method('getDuplicateQueries')->willReturn(['SELECT ?' => 2]);
        $profiler->method('totalElapsedMs')->willReturn(99.12345);

        return $profiler;
    }

    private function event(string $kind, string $label, float $duration, array $meta = [], ?string $source = null): array
    {
        return [
            'kind' => $kind, 'label' => $label, 'source' => $source, 'duration' => $duration,
            'meta' => $meta, 'memory' => 5, 'invocations' => 1, 'severity' => null,
        ];
    }

    public function testNothingIsWrittenWithoutEvents(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('insert');
        $this->assertNull($this->persister($connection)->persist($this->profiler([])));
    }

    public function testRunRowAndEventsArePersisted(): void
    {
        $events = [
            $this->event('query', 'SELECT 1', 60.0, ['fingerprint' => 'SELECT ?']),
            $this->event('query', 'SELECT 2', 400.0, ['fingerprint' => 'SELECT ?']),
            $this->event('block', str_repeat('L', 600), 1.0, [], str_repeat('S', 600)),
        ];

        $runId = $this->persister()->persist($this->profiler($events, str_repeat('r', 300)), 404);

        $this->assertSame(77, $runId);
        $this->assertCount(1, $this->inserts);
        [$table, $row] = $this->inserts[0];
        $this->assertSame('pfx_panth_perf_run', $table);
        $this->assertSame('tok', $row['token']);
        $this->assertSame(255, strlen($row['route']));
        $this->assertSame(404, $row['status_code']);
        $this->assertSame('frontend', $row['area']);
        $this->assertSame(99.123, $row['total_time']);
        $this->assertSame(12.346, $row['db_time']);
        $this->assertSame(2, $row['db_queries']);
        $this->assertSame(1, $row['db_slow']);
        $this->assertSame(1, $row['db_duplicates']);
        $this->assertSame(1, $row['block_count']);
        $this->assertSame(0, $row['observer_count']);
        $this->assertSame(0.0, $row['plugin_time']);
        $this->assertSame(2048, $row['memory_peak']);
        $this->assertSame(3, $row['bottleneck_count']);
        $this->assertSame(4, $row['severity_max']);

        $summary = json_decode($row['summary'], true);
        $this->assertSame(['SELECT ?' => 2], $summary['duplicates']);
        $this->assertCount(3, $summary['findings']);

        $this->assertCount(1, $this->multiInserts);
        [$eventTable, $rows] = $this->multiInserts[0];
        $this->assertSame('pfx_panth_perf_run_event', $eventTable);
        $this->assertCount(3, $rows);
        $this->assertSame(77, $rows[0]['run_id']);
        $this->assertSame('{"fingerprint":"SELECT ?"}', $rows[0]['meta']);
        $this->assertSame(5, $rows[0]['memory_delta']);
        $this->assertSame(500, strlen($rows[2]['label']));
        $this->assertSame(500, strlen($rows[2]['source']));
        $this->assertNull($rows[2]['meta']);
        $this->assertNull($rows[2]['severity']);
    }

    public function testStatusCodeDefaultsTo200AndAreaFallsBackToUnknown(): void
    {
        $this->persister(null, new \RuntimeException('area not set'))
            ->persist($this->profiler([$this->event('block', 'b', 1.0)]), 0);
        $this->assertSame(200, $this->inserts[0][1]['status_code']);
        $this->assertSame('unknown', $this->inserts[0][1]['area']);
        $this->assertSame(0, $this->inserts[0][1]['severity_max']);

        $this->inserts = [];
        $this->persister(null, '')->persist($this->profiler([$this->event('block', 'b', 1.0)]));
        $this->assertSame(200, $this->inserts[0][1]['status_code']);
        $this->assertSame('unknown', $this->inserts[0][1]['area']);
    }

    public function testEventsAreInsertedInBatchesOf500(): void
    {
        $events = array_fill(0, 1201, $this->event('observer', 'e', 0.1));
        $this->persister()->persist($this->profiler($events));

        $this->assertSame([500, 500, 201], array_map(static fn($c) => count($c[1]), $this->multiInserts));
    }
}
