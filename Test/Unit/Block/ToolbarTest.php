<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Block;

use Magento\Framework\View\Element\Template\Context;
use Panth\PerformanceDebugger\Block\Toolbar;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\BottleneckAnalyzer;
use Panth\PerformanceDebugger\Service\CaptureGate;
use Panth\PerformanceDebugger\Service\Profiler;
use PHPUnit\Framework\TestCase;

class ToolbarTest extends TestCase
{
    private function config(bool $showToolbar = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('showToolbar')->willReturn($showToolbar);
        $config->method('duplicateQueryThreshold')->willReturn(3);
        $config->method('slowQueryMs')->willReturn(50.0);
        $config->method('slowBlockMs')->willReturn(50.0);
        $config->method('slowObserverMs')->willReturn(30.0);

        return $config;
    }

    private function profiler(bool $active = true, array $events = [], array $duplicates = []): Profiler
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('isActive')->willReturn($active);
        $profiler->method('getEvents')->willReturn($events);
        $profiler->method('getAggregates')->willReturn(['block' => ['time' => 1.0, 'count' => 1]]);
        $profiler->method('getDuplicateQueries')->willReturn($duplicates);
        $profiler->method('totalElapsedMs')->willReturn(123.4567);
        $profiler->method('getRequestContext')->willReturn([
            'token' => 'tok',
            'url' => 'https://shop.test/x',
            'route' => 'cms/page/view',
            'memory_peak' => 3 * 1024 * 1024,
        ]);

        return $profiler;
    }

    private function toolbar(Config $config, Profiler $profiler, bool $canView = true): Toolbar
    {
        $gate = $this->createStub(CaptureGate::class);
        $gate->method('canViewToolbar')->willReturn($canView);

        return new Toolbar($this->createStub(Context::class), $config, $profiler, new BottleneckAnalyzer($config), $gate);
    }

    private function event(string $kind, string $label, float $duration, array $meta = [], ?string $source = null): array
    {
        return [
            'kind' => $kind, 'label' => $label, 'source' => $source, 'duration' => $duration,
            'meta' => $meta, 'memory' => 0, 'invocations' => 1, 'severity' => null,
        ];
    }

    public function testShouldRenderRequiresToolbarActiveProfilerAndPermission(): void
    {
        $this->assertTrue($this->toolbar($this->config(), $this->profiler())->shouldRender());
        $this->assertFalse($this->toolbar($this->config(false), $this->profiler())->shouldRender());
        $this->assertFalse($this->toolbar($this->config(), $this->profiler(false))->shouldRender());
        $this->assertFalse($this->toolbar($this->config(), $this->profiler(), false)->shouldRender());
    }

    public function testTemplateIsTheModuleToolbar(): void
    {
        $this->assertSame(
            'Panth_PerformanceDebugger::toolbar.phtml',
            $this->toolbar($this->config(), $this->profiler())->getTemplate()
        );
    }

    public function testPayloadGroupsEventsAndSplitsModules(): void
    {
        $events = [
            $this->event('block', 'header', 60.0, ['template' => 'Vendor_Mod::h.phtml'], 'Vendor_Mod::h.phtml'),
            $this->event('block', 'core', 10.0, ['class' => 'Magento\Theme\Block\Html'], null),
            $this->event('query', 'SELECT 1', 2.0, ['callsite' => ['summary' => 'Vendor\Mod\Repo->get (a.php:1)']],
                'Vendor\Mod\Repo->get (a.php:1)'),
            $this->event('observer', 'some_event', 1.0),
        ];
        $payload = $this->toolbar($this->config(), $this->profiler(true, $events, ['A' => 3, 'B' => 9]))->getPayload();

        $this->assertSame('tok', $payload['token']);
        $this->assertSame('cms/page/view', $payload['route']);
        $this->assertSame(123.46, $payload['totalMs']);
        $this->assertSame(3.0, $payload['memoryPeakMb']);
        $this->assertSame(['block', 'query', 'observer'], array_keys($payload['eventsByKind']));
        $this->assertCount(2, $payload['eventsByKind']['block']);

        $header = $payload['eventsByKind']['block'][0];
        $this->assertSame('Vendor_Mod::h.phtml', $header['origin']);
        $this->assertSame('Vendor_Mod', $header['module']);
        $core = $payload['eventsByKind']['block'][1];
        $this->assertSame('Magento\Theme\Block\Html', $core['origin']);
        $this->assertSame('Magento_Theme', $core['module']);
        $this->assertSame('Vendor\Mod\Repo->get (a.php:1)', $payload['eventsByKind']['query'][0]['origin']);
        $observer = $payload['eventsByKind']['observer'][0];
        $this->assertSame('', $observer['origin']);
        $this->assertNull($observer['module']);

        $this->assertSame([['fingerprint' => 'B', 'count' => 9], ['fingerprint' => 'A', 'count' => 3]], $payload['duplicates']);

        $this->assertSame(['Vendor_Mod'], array_column($payload['modulesUserland'], 'module'));
        $this->assertSame($payload['modulesUserland'], $payload['modules']);
        $vendor = $payload['modulesUserland'][0];
        $this->assertSame(62.0, $vendor['time']);
        $this->assertSame(2, $vendor['count']);
        $this->assertSame(1, $vendor['blocks']);
        $this->assertSame(1, $vendor['queries']);
        $this->assertSame(['Magento_Theme'], array_column($payload['modulesCore'], 'module'));

        $this->assertCount(1, $payload['findings']);
        $this->assertSame('slow_block', $payload['findings'][0]['kind']);
        $this->assertCount(1, $payload['userlandFindings']);
        $this->assertSame([], $payload['coreFindings']);
        $this->assertSame(42.0, $payload['totalEstimatedSavings']);
        $this->assertSame(42.0, $payload['totalEstimatedSavingsAll']);
    }

    public function testDuplicatesAreCappedAtTwenty(): void
    {
        $dupes = [];
        foreach (range(1, 30) as $i) {
            $dupes['fp' . $i] = $i;
        }
        $payload = $this->toolbar($this->config(), $this->profiler(true, [], $dupes))->getPayload();
        $this->assertCount(20, $payload['duplicates']);
        $this->assertSame(30, $payload['duplicates'][0]['count']);
    }

    public function testPayloadJsonIsValidAndUnescapesSlashes(): void
    {
        $json = $this->toolbar($this->config(), $this->profiler())->getPayloadJson();
        $decoded = json_decode($json, true);
        $this->assertSame('https://shop.test/x', $decoded['url']);
        $this->assertStringContainsString('"https://shop.test/x"', $json);
    }
}
