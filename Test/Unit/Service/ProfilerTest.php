<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Service;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Math\Random;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\CaptureGate;
use Panth\PerformanceDebugger\Service\Profiler;
use Panth\PerformanceDebugger\Service\Redactor;
use PHPUnit\Framework\TestCase;

class ProfilerTest extends TestCase
{
    private function profiler(
        bool $enabled = true,
        bool $capture = true,
        array $configValues = [],
        ?HttpRequest $request = null,
        ?StoreManagerInterface $storeManager = null,
        ?Random $random = null
    ): Profiler {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('maxEventsPerRun')->willReturn($configValues['max'] ?? 5000);
        $config->method('trackMemory')->willReturn($configValues['memory'] ?? false);
        $config->method('slowQueryMs')->willReturn($configValues['slow'] ?? 50.0);
        $config->method('duplicateQueryThreshold')->willReturn($configValues['dupes'] ?? 3);

        $gate = $this->createStub(CaptureGate::class);
        $gate->method('shouldCapture')->willReturn($capture);

        if ($random === null) {
            $random = $this->createStub(Random::class);
            $random->method('getRandomString')->willReturn('TOKEN1234567890A');
        }

        return new Profiler(
            $config,
            $random,
            $request ?? $this->createStub(HttpRequest::class),
            $storeManager ?? $this->createStub(StoreManagerInterface::class),
            $gate,
            new Redactor()
        );
    }

    public function testStartRequiresEnabledAndCaptureGate(): void
    {
        $disabled = $this->profiler(false);
        $disabled->start();
        $this->assertFalse($disabled->isActive());
        $this->assertSame('', $disabled->getToken());

        $gated = $this->profiler(true, false);
        $gated->start();
        $this->assertFalse($gated->isActive());

        $active = $this->profiler();
        $active->start();
        $this->assertTrue($active->isActive());
        $this->assertSame('TOKEN1234567890A', $active->getToken());
    }

    public function testStartIsIdempotent(): void
    {
        $random = $this->createMock(Random::class);
        $random->expects($this->once())->method('getRandomString')->with(16)->willReturn('ONCE');
        $profiler = $this->profiler(true, true, [], null, null, $random);
        $profiler->start();
        $profiler->start();
        $this->assertSame('ONCE', $profiler->getToken());
    }

    public function testRecordIsIgnoredWhenInactive(): void
    {
        $profiler = $this->profiler();
        $profiler->record('block', 'header', 10.0);
        $this->assertSame([], $profiler->getEvents());
        $this->assertSame(0, $profiler->getAggregates()['block']['count']);
        $this->assertSame(0.0, $profiler->totalElapsedMs());
    }

    public function testRecordBuildsEventsAndAggregates(): void
    {
        $profiler = $this->profiler(true, true, ['slow' => 20.0]);
        $profiler->start();
        $profiler->record('block', 'header', 12.34567, ['template' => 'x.phtml'], 'x.phtml');
        $profiler->record('query', 'SELECT 1', 25.0, ['fingerprint' => 'SELECT ?']);
        $profiler->record('query', 'SELECT 2', 5.0, ['fingerprint' => 'SELECT ?']);
        $profiler->record('custom', 'other', 1.0);

        $events = $profiler->getEvents();
        $this->assertCount(4, $events);
        $this->assertSame([
            'kind' => 'block',
            'label' => 'header',
            'source' => 'x.phtml',
            'duration' => 12.346,
            'meta' => ['template' => 'x.phtml'],
            'memory' => 0,
            'invocations' => 1,
            'severity' => null,
        ], $events[0]);

        $agg = $profiler->getAggregates();
        $this->assertSame(1, $agg['block']['count']);
        $this->assertEqualsWithDelta(12.34567, $agg['block']['time'], 0.00001);
        $this->assertSame(2, $agg['query']['count']);
        $this->assertSame(1, $agg['query']['slow']);
        $this->assertEqualsWithDelta(30.0, $agg['query']['time'], 0.0001);
        $this->assertArrayNotHasKey('custom', $agg);
    }

    public function testMemoryIsRecordedOnlyWhenTracked(): void
    {
        $profiler = $this->profiler(true, true, ['memory' => true]);
        $profiler->start();
        $profiler->record('block', 'a', 1.0);
        $this->assertGreaterThan(0, $profiler->getEvents()[0]['memory']);
    }

    public function testMaxEventsCapIsEnforced(): void
    {
        $profiler = $this->profiler(true, true, ['max' => 2]);
        $profiler->start();
        foreach (range(1, 5) as $i) {
            $profiler->record('observer', 'event' . $i, 1.0);
        }
        $this->assertCount(2, $profiler->getEvents());
        $this->assertSame(2, $profiler->getAggregates()['observer']['count']);
    }

    public function testDuplicateQueriesRespectThresholdAndSortDescending(): void
    {
        $profiler = $this->profiler(true, true, ['dupes' => 2]);
        $profiler->start();
        foreach (['A', 'B', 'B', 'B', 'A', 'C'] as $fp) {
            $profiler->record('query', 'q', 1.0, ['fingerprint' => $fp]);
        }
        $profiler->record('block', 'b', 1.0, ['fingerprint' => 'A']);

        $this->assertSame(['B' => 3, 'A' => 2], $profiler->getDuplicateQueries());
    }

    public function testResetClearsStateButKeepsAggregateShape(): void
    {
        $profiler = $this->profiler(true, true, ['slow' => 1.0, 'dupes' => 1]);
        $profiler->start();
        $profiler->record('query', 'q', 5.0, ['fingerprint' => 'A']);
        $profiler->reset();

        $this->assertFalse($profiler->isActive());
        $this->assertSame([], $profiler->getEvents());
        $this->assertSame([], $profiler->getDuplicateQueries());
        $agg = $profiler->getAggregates();
        $this->assertSame(['time' => 0.0, 'count' => 0, 'slow' => 0], $agg['query']);
        $this->assertSame(['time' => 0.0, 'count' => 0], $agg['block']);
        $this->assertCount(7, $agg);
    }

    public function testTotalElapsedGrowsAfterStart(): void
    {
        $profiler = $this->profiler();
        $profiler->start();
        $this->assertGreaterThanOrEqual(0.0, $profiler->totalElapsedMs());
        $this->assertLessThan(60000.0, $profiler->totalElapsedMs());
    }

    public function testRequestContextSanitizesUrlAndBuildsRoute(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getUriString')->willReturn('https://shop.test/checkout?token=abc&step=2');
        $request->method('getMethod')->willReturn('POST');
        $request->method('getRouteName')->willReturn('checkout');
        $request->method('getControllerName')->willReturn('index');
        $request->method('getActionName')->willReturn('index');

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('3');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $profiler = $this->profiler(true, true, [], $request, $storeManager);
        $profiler->start();
        $context = $profiler->getRequestContext();

        $this->assertSame('https://shop.test/checkout?token=***&step=2', $context['url']);
        $this->assertSame('POST', $context['method']);
        $this->assertSame('checkout/index/index', $context['route']);
        $this->assertSame(3, $context['store_id']);
        $this->assertSame('TOKEN1234567890A', $context['token']);
        $this->assertGreaterThan(0, $context['memory_peak']);
    }

    public function testRequestContextToleratesMissingRouteAndStore(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $context = $this->profiler(true, true, [], null, $storeManager)->getRequestContext();
        $this->assertSame('', $context['route']);
        $this->assertSame(0, $context['store_id']);
        $this->assertSame('', $context['url']);
        $this->assertSame('', $context['token']);
    }
}
