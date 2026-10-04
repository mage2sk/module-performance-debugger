<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Panth\PerformanceDebugger\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[substr($path, strlen(Config::XML_PATH_PREFIX))] ?? null
        );
        $scope->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool) ($flags[substr($path, strlen(Config::XML_PATH_PREFIX))] ?? false)
        );

        return new Config($scope);
    }

    public function testToolbarRequiresModuleEnabled(): void
    {
        $this->assertFalse($this->config([], ['general/show_toolbar' => true])->showToolbar());
        $this->assertTrue(
            $this->config([], ['general/enabled' => true, 'general/show_toolbar' => true])->showToolbar()
        );
        $this->assertFalse($this->config([], ['general/enabled' => true])->showToolbar());
    }

    public function testSessionLifetimeDefaultsAndClamps(): void
    {
        $this->assertSame(4, $this->config()->sessionLifetimeHours());
        $this->assertSame(4, $this->config(['general/session_lifetime' => '-5'])->sessionLifetimeHours());
        $this->assertSame(12, $this->config(['general/session_lifetime' => '12'])->sessionLifetimeHours());
        $this->assertSame(168, $this->config(['general/session_lifetime' => '9999'])->sessionLifetimeHours());
    }

    public function testAllowedIpsAreTrimmedAndEmptyEntriesDropped(): void
    {
        $this->assertSame([], $this->config()->allowedIps());
        $this->assertSame(
            ['1.2.3.4', '10.0.0.1', '*'],
            $this->config(['general/allowed_ips' => ' 1.2.3.4 , ,10.0.0.1,*, '])->allowedIps()
        );
    }

    public static function clientProvider(): array
    {
        return [
            'listed ip in production' => ['1.2.3.4', '1.2.3.4', State::MODE_PRODUCTION, true],
            'unlisted ip in production even with wildcard' => ['1.2.3.4,*', '8.8.8.8', State::MODE_PRODUCTION, false],
            'wildcard in default mode' => ['*', '8.8.8.8', State::MODE_DEFAULT, true],
            'localhost in developer mode' => ['', '127.0.0.1', State::MODE_DEVELOPER, true],
            'private lan in developer mode' => ['', '192.168.1.20', State::MODE_DEVELOPER, true],
            'public ip in developer mode' => ['', '8.8.8.8', State::MODE_DEVELOPER, false],
            'localhost in default mode' => ['', '127.0.0.1', State::MODE_DEFAULT, false],
            'empty address in developer mode' => ['', '', State::MODE_DEVELOPER, false],
            'garbage address in developer mode' => ['', 'not-an-ip', State::MODE_DEVELOPER, false],
        ];
    }

    #[DataProvider('clientProvider')]
    public function testIsClientAllowed(string $allowed, string $ip, string $mode, bool $expected): void
    {
        $config = $this->config(['general/allowed_ips' => $allowed]);
        $this->assertSame($expected, $config->isClientAllowed($ip, $mode));
    }

    public function testSafeModeDisablesPluginAndDiTracking(): void
    {
        $flags = ['collectors/track_plugins' => true, 'collectors/track_di' => true];
        $normal = $this->config([], $flags);
        $this->assertTrue($normal->trackPlugins());
        $this->assertTrue($normal->trackDi());

        $safe = $this->config([], $flags + ['general/safe_mode' => true]);
        $this->assertTrue($safe->safeMode());
        $this->assertFalse($safe->trackPlugins());
        $this->assertFalse($safe->trackDi());
    }

    public function testThresholdDefaults(): void
    {
        $config = $this->config();
        $this->assertSame(50.0, $config->slowQueryMs());
        $this->assertSame(50.0, $config->slowBlockMs());
        $this->assertSame(30.0, $config->slowObserverMs());
        $this->assertSame(20.0, $config->slowPluginMs());
        $this->assertSame(3, $config->duplicateQueryThreshold());
        $this->assertSame(24, $config->retentionHours());
        $this->assertSame(5000, $config->maxEventsPerRun());
    }

    public function testThresholdOverrides(): void
    {
        $config = $this->config([
            'thresholds/slow_query_ms' => '12.5',
            'thresholds/slow_block_ms' => '80',
            'thresholds/slow_observer_ms' => '15',
            'thresholds/slow_plugin_ms' => '5',
            'thresholds/duplicate_query_threshold' => '7',
            'storage/retention_hours' => '48',
            'storage/max_events_per_run' => '10',
        ]);
        $this->assertSame(12.5, $config->slowQueryMs());
        $this->assertSame(80.0, $config->slowBlockMs());
        $this->assertSame(15.0, $config->slowObserverMs());
        $this->assertSame(5.0, $config->slowPluginMs());
        $this->assertSame(7, $config->duplicateQueryThreshold());
        $this->assertSame(48, $config->retentionHours());
        $this->assertSame(10, $config->maxEventsPerRun());
    }

    public function testSimpleFlags(): void
    {
        $config = $this->config([], [
            'collectors/track_blocks' => true,
            'collectors/track_db' => true,
            'storage/persist_runs' => true,
            'export/enable_pdf' => true,
        ]);
        $this->assertTrue($config->trackBlocks());
        $this->assertTrue($config->trackDb());
        $this->assertTrue($config->persistRuns());
        $this->assertTrue($config->enablePdf());
        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->trackObservers());
        $this->assertFalse($config->trackLayout());
        $this->assertFalse($config->trackMemory());
        $this->assertFalse($config->enableXls());
        $this->assertFalse($config->toolbarForAllowedIps());
    }
}
