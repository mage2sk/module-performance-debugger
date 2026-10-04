<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Plugin;

use Magento\Framework\DB\LoggerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Plugin\DbLoggerPlugin;
use Panth\PerformanceDebugger\Service\Profiler;
use Panth\PerformanceDebugger\Service\Redactor;
use PHPUnit\Framework\TestCase;

class DbLoggerPluginTest extends TestCase
{
    private array $recorded = [];

    private function plugin(bool $active = true, bool $trackDb = true): DbLoggerPlugin
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('isActive')->willReturn($active);
        $profiler->method('record')->willReturnCallback(function (...$args) {
            $this->recorded[] = $args;
        });
        $config = $this->createStub(Config::class);
        $config->method('trackDb')->willReturn($trackDb);

        return new DbLoggerPlugin($profiler, $config, new Redactor());
    }

    private function logQuery(DbLoggerPlugin $plugin, string $sql, array $bind = [], string $type = LoggerInterface::TYPE_QUERY): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $plugin->afterStartTimer($logger, null);
        $plugin->afterLogStats($logger, null, $type, $sql, $bind);
    }

    public function testQueryIsRecordedWithFingerprintRedactedLabelAndSafeBind(): void
    {
        $this->logQuery(
            $this->plugin(),
            "SELECT *\n  FROM t WHERE id = 15 AND sku = 'abc' AND x IN (1, 2, 3)",
            ['id' => 15, 'email' => 'bob@example.com']
        );

        $this->assertCount(1, $this->recorded);
        [$kind, $label, $duration, $meta, $source] = $this->recorded[0];
        $this->assertSame('query', $kind);
        $this->assertSame("SELECT * FROM t WHERE id = 15 AND sku = '?' AND x IN (1, 2, 3)", $label);
        $this->assertGreaterThanOrEqual(0.0, $duration);
        $this->assertSame('SELECT * FROM t WHERE id = ? AND sku = ? AND x IN (?)', $meta['fingerprint']);
        $this->assertSame(['id' => 15, 'email' => '[string:15]'], $meta['bind']);
        $this->assertSame($meta['callsite']['summary'], $source);
    }

    public function testCallsiteSkipsPluginFramesAndPointsAtCaller(): void
    {
        $this->logQuery($this->plugin(), 'SELECT 1');
        $callsite = $this->recorded[0][3]['callsite'];

        $this->assertStringStartsWith(self::class . '->logQuery (', $callsite['summary']);
        $this->assertNotEmpty($callsite['trail']);
        $this->assertLessThanOrEqual(5, count($callsite['trail']));
        foreach ($callsite['trail'] as $frame) {
            $this->assertStringNotContainsString('DbLoggerPlugin->', $frame['callable']);
            $this->assertStringStartsNotWith('/var/www/html/', $frame['file']);
        }
    }

    public function testFingerprintsMatchForQueriesDifferingOnlyInLiterals(): void
    {
        $plugin = $this->plugin();
        $this->logQuery($plugin, "SELECT * FROM t WHERE id = 1 AND name = \"a\"");
        $this->logQuery($plugin, "SELECT  * FROM t WHERE id = 99 AND name = \"zz\"");
        $this->assertSame($this->recorded[0][3]['fingerprint'], $this->recorded[1][3]['fingerprint']);
    }

    public function testNonQueryStatementsAreRecordedWithTypePrefixOnly(): void
    {
        $this->logQuery($this->plugin(), 'mysql:host=db', [], LoggerInterface::TYPE_CONNECT);
        $this->assertSame(['query', 'CONNECT mysql:host=db'], array_slice($this->recorded[0], 0, 2));
        $this->assertSame([], $this->recorded[0][3]);
        $this->assertNull($this->recorded[0][4]);
    }

    public function testLongSqlIsTruncated(): void
    {
        $this->logQuery($this->plugin(), 'SELECT ' . str_repeat('a', 600));
        $label = $this->recorded[0][1];
        $this->assertSame(480, strlen($label));
        $this->assertStringEndsWith('...', $label);
    }

    public function testNothingRecordedWithoutStartTimerOrWhenDisabled(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $this->plugin()->afterLogStats($logger, null, LoggerInterface::TYPE_QUERY, 'SELECT 1');
        $this->logQuery($this->plugin(false), 'SELECT 1');
        $this->logQuery($this->plugin(true, false), 'SELECT 1');

        $this->assertSame([], $this->recorded);
    }

    public function testTimerIsConsumedByOneLogStatsCall(): void
    {
        $plugin = $this->plugin();
        $logger = $this->createStub(LoggerInterface::class);
        $plugin->afterStartTimer($logger, null);
        $plugin->afterLogStats($logger, null, LoggerInterface::TYPE_QUERY, 'SELECT 1');
        $plugin->afterLogStats($logger, null, LoggerInterface::TYPE_QUERY, 'SELECT 2');
        $this->assertCount(1, $this->recorded);
    }
}
