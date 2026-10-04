<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\PerformanceDebugger\Console\Command\CleanupCommand;
use Panth\PerformanceDebugger\Cron\CleanupRuns;
use Panth\PerformanceDebugger\Helper\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupRunsTest extends TestCase
{
    private function cleanup(int $retention, ?array &$deleted): CleanupRuns
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function ($table, $where) use (&$deleted) {
            $deleted = [$table, $where];
            return 3;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn($t) => 'pfx_' . $t);

        $config = $this->createStub(Config::class);
        $config->method('retentionHours')->willReturn($retention);

        return new CleanupRuns($resource, $config);
    }

    private function cutoffOf(array $deleted): int
    {
        return (int) strtotime($deleted[1]['created_at < ?']);
    }

    public function testDeletesRunsOlderThanRetention(): void
    {
        $deleted = null;
        $this->cleanup(48, $deleted)->execute();

        $this->assertSame('pfx_panth_perf_run', $deleted[0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $deleted[1]['created_at < ?']);
        $this->assertEqualsWithDelta(time() - 48 * 3600, $this->cutoffOf($deleted), 5);
    }

    public function testRetentionIsAtLeastOneHour(): void
    {
        $deleted = null;
        $this->cleanup(0, $deleted)->execute();
        $this->assertEqualsWithDelta(time() - 3600, $this->cutoffOf($deleted), 5);
    }

    public function testCommandRunsCleanupAndReportsSuccess(): void
    {
        $cron = $this->createMock(CleanupRuns::class);
        $cron->expects($this->once())->method('execute');

        $command = new CleanupCommand($cron);
        $this->assertSame('panth:perf:cleanup', $command->getName());

        $tester = new CommandTester($command);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('older than retention have been removed', $tester->getDisplay());
    }
}
