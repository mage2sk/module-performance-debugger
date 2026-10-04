<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\PerformanceDebugger\Service\Redactor;
use Panth\PerformanceDebugger\Setup\Patch\Data\RedactStoredRuns;
use PHPUnit\Framework\TestCase;

class RedactStoredRunsTest extends TestCase
{
    private array $updates = [];
    private array $selectTables = [];

    private function patch(array $existingTables, array $runRows, array $eventRows): RedactStoredRuns
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            static fn($table) => in_array($table, $existingTables, true)
        );
        $connection->method('select')->willReturnCallback(function () {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnCallback(function ($table) use ($select) {
                $this->selectTables[spl_object_id($select)] = $table;
                return $select;
            });
            foreach (['where', 'order', 'limit'] as $method) {
                $select->method($method)->willReturnSelf();
            }
            return $select;
        });
        $connection->method('fetchAll')->willReturnCallback(
            function ($select) use (&$runRows, &$eventRows) {
                $isRun = ($this->selectTables[spl_object_id($select)] ?? '') === 'pfx_panth_perf_run';
                $rows = $isRun ? $runRows : $eventRows;
                $batch = array_shift($rows) ?? [];
                if ($isRun) {
                    $runRows = $rows;
                } else {
                    $eventRows = $rows;
                }
                return $batch;
            }
        );
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$table, $data, $where];
            return 1;
        });

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn($t) => 'pfx_' . $t);

        return new RedactStoredRuns($setup, new Redactor());
    }

    public function testMissingTablesAreSkipped(): void
    {
        $patch = $this->patch([], [[['run_id' => 1, 'url' => '/?token=x', 'summary' => null]]], []);
        $this->assertSame($patch, $patch->apply());
        $this->assertSame([], $this->updates);
    }

    public function testRunUrlsAndSummariesAreRedacted(): void
    {
        $summary = json_encode([
            'duplicates' => ["SELECT * FROM c WHERE email = 'a@b.c'" => 2, "SELECT * FROM c WHERE email = 'x@y.z'" => 3],
            'findings' => [
                [
                    'kind' => 'duplicate_query',
                    'source' => "SELECT * FROM c WHERE email = 'a@b.c'",
                    'title' => "keep 'title'",
                    'binds' => [['name' => 'email', 'sample' => ['a@b.c']]],
                ],
                ['kind' => 'slow_block', 'source' => "Vendor_Mod::x.phtml 'literal'"],
            ],
        ]);
        $rows = [[
            ['run_id' => 1, 'url' => 'https://shop.test/?token=secret', 'summary' => $summary],
            ['run_id' => 2, 'url' => 'https://shop.test/clean', 'summary' => ''],
        ]];

        $this->patch(['pfx_panth_perf_run'], $rows, [])->apply();

        $this->assertCount(1, $this->updates);
        [$table, $data, $where] = $this->updates[0];
        $this->assertSame('pfx_panth_perf_run', $table);
        $this->assertSame(['run_id = ?' => 1], $where);
        $this->assertSame('https://shop.test/?token=***', $data['url']);

        $decoded = json_decode($data['summary'], true);
        $this->assertSame(["SELECT * FROM c WHERE email = '?'" => 5], $decoded['duplicates']);
        $this->assertSame("SELECT * FROM c WHERE email = '?'", $decoded['findings'][0]['source']);
        $this->assertSame("keep 'title'", $decoded['findings'][0]['title']);
        $this->assertSame([['name' => 'email', 'sample' => ['[string:5]']]], $decoded['findings'][0]['binds']);
        $this->assertSame("Vendor_Mod::x.phtml 'literal'", $decoded['findings'][1]['source']);
    }

    public function testAlreadyCleanRowsAreNotRewritten(): void
    {
        $summary = '{"findings":[{"kind":"slow_query","source":"SELECT 1"}]}';
        $this->patch(['pfx_panth_perf_run'], [[['run_id' => 3, 'url' => '/x', 'summary' => $summary]]], [])->apply();
        $this->assertSame([], $this->updates);
    }

    public function testQueryEventLabelsAndMetaAreRedacted(): void
    {
        $meta = json_encode(['bind' => ['email' => 'a@b.c', 'id' => 4], 'fingerprint' => "x = 'y'", 'other' => 'keep']);
        $rows = [[
            ['event_id' => 10, 'label' => "SELECT * FROM t WHERE n = 'bob'", 'meta' => $meta],
            ['event_id' => 11, 'label' => 'SELECT 1', 'meta' => null],
        ]];

        $this->patch(['pfx_panth_perf_run_event'], [], $rows)->apply();

        $this->assertCount(1, $this->updates);
        [$table, $data, $where] = $this->updates[0];
        $this->assertSame('pfx_panth_perf_run_event', $table);
        $this->assertSame(['event_id = ?' => 10], $where);
        $this->assertSame("SELECT * FROM t WHERE n = '?'", $data['label']);
        $this->assertSame(
            ['bind' => ['email' => '[string:5]', 'id' => 4], 'fingerprint' => "x = '?'", 'other' => 'keep'],
            json_decode($data['meta'], true)
        );
    }

    public function testFullBatchTriggersAnotherFetch(): void
    {
        $batch = [];
        foreach (range(1, 500) as $i) {
            $batch[] = ['event_id' => $i, 'label' => 'SELECT 1', 'meta' => null];
        }
        $second = [['event_id' => 501, 'label' => "SELECT 'a'", 'meta' => null]];

        $this->patch(['pfx_panth_perf_run_event'], [], [$batch, $second])->apply();

        $this->assertCount(1, $this->updates);
        $this->assertSame(['event_id = ?' => 501], $this->updates[0][2]);
    }

    public function testUndecodableJsonIsLeftUnchanged(): void
    {
        $this->patch(
            ['pfx_panth_perf_run', 'pfx_panth_perf_run_event'],
            [[['run_id' => 4, 'url' => '/x', 'summary' => '{broken']]],
            [[['event_id' => 12, 'label' => 'SELECT 1', 'meta' => 'not json']]]
        )->apply();
        $this->assertSame([], $this->updates);
    }

    public function testBindNamesAreKeptWhileSampleValuesAreMasked(): void
    {
        $summary = json_encode(['findings' => [[
            'kind' => 'slow_query',
            'source' => 'SELECT 1',
            'binds' => [['name' => 'customer_email', 'distinct' => 2, 'sample' => ['a@b.c', '7'], 'total' => 3]],
        ]]]);
        $this->patch(['pfx_panth_perf_run'], [[['run_id' => 5, 'url' => '/x', 'summary' => $summary]]], [])->apply();
        $this->assertCount(1, $this->updates);
        $decoded = json_decode($this->updates[0][1]['summary'], true);
        $this->assertSame(
            [['name' => 'customer_email', 'distinct' => 2, 'sample' => ['[string:5]', '7'], 'total' => 3]],
            $decoded['findings'][0]['binds']
        );
    }

    public function testPatchMetadata(): void
    {
        $this->assertSame([], RedactStoredRuns::getDependencies());
        $this->assertSame([], $this->patch([], [], [])->getAliases());
    }
}
