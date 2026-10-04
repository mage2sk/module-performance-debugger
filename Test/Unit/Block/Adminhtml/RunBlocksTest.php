<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\PerformanceDebugger\Block\Adminhtml\RunDetail;
use Panth\PerformanceDebugger\Block\Adminhtml\RunGrid;
use Panth\PerformanceDebugger\Model\RunRepository;
use PHPUnit\Framework\TestCase;

class RunBlocksTest extends TestCase
{
    private ?ObjectManagerInterface $previousObjectManager = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn($class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }

    private function request(array $params): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        return $request;
    }

    private function context(HttpRequest $request, ?StoreManagerInterface $storeManager = null): Context
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = '', $params = []) => $route . '?' . http_build_query((array) $params)
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getStoreManager')->willReturn(
            $storeManager ?? $this->createStub(StoreManagerInterface::class)
        );

        return $context;
    }

    private function grid(array $params = [], ?RunRepository $repository = null, ?StoreManagerInterface $stores = null): RunGrid
    {
        return new RunGrid(
            $this->context($this->request($params), $stores),
            $repository ?? $this->createStub(RunRepository::class)
        );
    }

    public function testGridParameterDefaults(): void
    {
        $grid = $this->grid();
        $this->assertSame(1, $grid->getPage());
        $this->assertSame(50, $grid->getPageSize());
        $this->assertSame('created_at', $grid->getSort());
        $this->assertSame('DESC', $grid->getDir());
        $this->assertSame('', $grid->getQuery());
        $this->assertSame(0, $grid->getMinSeverity());
    }

    public function testGridParameterNormalisation(): void
    {
        $grid = $this->grid(['p' => '-4', 'limit' => '33', 'dir' => 'asc', 'q' => '  checkout ', 'sev' => '3']);
        $this->assertSame(1, $grid->getPage());
        $this->assertSame(50, $grid->getPageSize());
        $this->assertSame('ASC', $grid->getDir());
        $this->assertSame('checkout', $grid->getQuery());
        $this->assertSame(3, $grid->getMinSeverity());

        $this->assertSame(250, $this->grid(['limit' => '250'])->getPageSize());
        $this->assertSame('DESC', $this->grid(['dir' => 'sideways'])->getDir());
    }

    public function testResultIsFetchedOnceWithCurrentFilters(): void
    {
        $repository = $this->createMock(RunRepository::class);
        $repository->expects($this->once())->method('getPaged')
            ->with(2, 25, 'total_time', 'ASC', ['q' => 'cart', 'severity' => 2])
            ->willReturn(['rows' => [['run_id' => 1], ['run_id' => 2]], 'total' => 51]);

        $grid = $this->grid(
            ['p' => '2', 'limit' => '25', 'sort' => 'total_time', 'dir' => 'ASC', 'q' => 'cart', 'sev' => '2'],
            $repository
        );
        $this->assertCount(2, $grid->getRuns());
        $this->assertSame(51, $grid->getTotal());
        $this->assertSame(3, $grid->getTotalPages());
    }

    public function testEmptyResultYieldsZeroTotals(): void
    {
        $repository = $this->createStub(RunRepository::class);
        $repository->method('getPaged')->willReturn([]);
        $grid = $this->grid([], $repository);
        $this->assertSame([], $grid->getRuns());
        $this->assertSame(0, $grid->getTotal());
        $this->assertSame(0, $grid->getTotalPages());
    }

    public function testGridUrlDropsEmptyValuesAndAppliesOverrides(): void
    {
        $grid = $this->grid(['q' => 'abc']);
        $this->assertSame(
            'performancedebugger/run/index?p=1&limit=50&sort=created_at&dir=DESC&q=abc',
            $grid->gridUrl()
        );
        $this->assertSame(
            'performancedebugger/run/index?p=3&limit=50&sort=created_at&dir=DESC&sev=4',
            $grid->gridUrl(['p' => 3, 'q' => '', 'sev' => 4])
        );
    }

    public function testSortUrlTogglesDirectionOnlyForCurrentColumn(): void
    {
        $grid = $this->grid(['sort' => 'route', 'dir' => 'DESC', 'p' => 5]);
        $this->assertStringContainsString('sort=route&dir=ASC', $grid->sortUrl('route'));
        $this->assertStringContainsString('p=1', $grid->sortUrl('route'));
        $this->assertStringContainsString('sort=url&dir=DESC', $grid->sortUrl('url'));

        $asc = $this->grid(['sort' => 'route', 'dir' => 'ASC']);
        $this->assertStringContainsString('sort=route&dir=DESC', $asc->sortUrl('route'));
    }

    public function testSortIndicatorDiffersPerState(): void
    {
        $grid = $this->grid(['sort' => 'route', 'dir' => 'ASC']);
        $desc = $this->grid(['sort' => 'route', 'dir' => 'DESC']);
        $other = $grid->sortIndicator('url');

        $this->assertNotSame($other, $grid->sortIndicator('route'));
        $this->assertNotSame($grid->sortIndicator('route'), $desc->sortIndicator('route'));
        $this->assertSame($other, $desc->sortIndicator('url'));
    }

    public function testSessionLinksOnlyForActiveStores(): void
    {
        $active = $this->createStub(Store::class);
        $active->method('isActive')->willReturn(true);
        $active->method('getName')->willReturn('Main');
        $active->method('getId')->willReturn('2');
        $inactive = $this->createStub(Store::class);
        $inactive->method('isActive')->willReturn(false);

        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$active, $inactive]);

        $this->assertSame([[
            'label' => 'Main',
            'start' => 'performancedebugger/run/session?store=2',
            'stop' => 'performancedebugger/run/session?store=2&stop=1',
        ]], $this->grid([], null, $stores)->getSessionLinks());
    }

    public function testRowUrls(): void
    {
        $grid = $this->grid();
        $this->assertSame('performancedebugger/run/view?id=7', $grid->getViewUrl(7));
        $this->assertSame('performancedebugger/run/exportXls?id=7', $grid->getXlsUrl(7));
        $this->assertSame('performancedebugger/run/exportPdf?id=7', $grid->getPdfUrl(7));
    }

    public function testRunDetailLoadsRunAndEventsForRequestedId(): void
    {
        $request = $this->request(['id' => '12']);
        $repository = $this->createMock(RunRepository::class);
        $repository->expects($this->once())->method('getById')->with(12)->willReturn(['run_id' => 12]);
        $repository->expects($this->once())->method('getEvents')->with(12)->willReturn([['event_id' => 1]]);

        $detail = new RunDetail($this->context($request), $repository, $request);
        $this->assertSame(['run_id' => 12], $detail->getRun());
        $this->assertSame([['event_id' => 1]], $detail->getEvents());
        $this->assertSame('performancedebugger/run/exportXls?id=12', $detail->getXlsUrl());
        $this->assertSame('performancedebugger/run/exportPdf?id=12', $detail->getPdfUrl());
        $this->assertSame('performancedebugger/run/index?', $detail->getBackUrl());
    }
}
