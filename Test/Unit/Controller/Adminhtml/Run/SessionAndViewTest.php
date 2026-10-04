<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Controller\Adminhtml\Run;

use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\PerformanceDebugger\Controller\Adminhtml\Run\Session;
use Panth\PerformanceDebugger\Controller\Adminhtml\Run\View;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunRepository;
use Panth\PerformanceDebugger\Service\CaptureGate;

class SessionAndViewTest extends ControllerTestCase
{
    private function store(int $id, string $baseUrl = 'https://shop.test/'): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        return $store;
    }

    private function session(
        array $params,
        ?StoreManagerInterface $stores = null,
        bool $enabled = true,
        ?CaptureGate $gate = null
    ): Session {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('sessionLifetimeHours')->willReturn(2);

        if ($stores === null) {
            $stores = $this->createStub(StoreManagerInterface::class);
            $stores->method('getStore')->willReturn($this->store(1));
            $stores->method('getDefaultStoreView')->willReturn($this->store(1));
        }

        return new Session(
            $this->context($params),
            $gate ?? $this->createStub(CaptureGate::class),
            $config,
            $stores
        );
    }

    public function testSessionStartRedirectsToStorefrontWithSignedToken(): void
    {
        $gate = $this->createMock(CaptureGate::class);
        $gate->expects($this->once())->method('createToken')->with(7200)->willReturn('1700000000.abc');

        $this->session(['store' => '3'], null, true, $gate)->execute();

        $this->assertSame(['url' => 'https://shop.test/?panth_perf=1700000000.abc'], $this->redirect);
        $this->assertSame([], $this->errors);
    }

    public function testSessionUsesAmpersandWhenBaseUrlHasQuery(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($this->store(1, 'https://shop.test/?___store=en'));
        $gate = $this->createStub(CaptureGate::class);
        $gate->method('createToken')->willReturn('t');

        $this->session([], $stores, true, $gate)->execute();
        $this->assertSame(['url' => 'https://shop.test/?___store=en&panth_perf=t'], $this->redirect);
    }

    public function testSessionStopSendsZeroToken(): void
    {
        $gate = $this->createMock(CaptureGate::class);
        $gate->expects($this->never())->method('createToken');

        $this->session(['store' => '1', 'stop' => '1'], null, true, $gate)->execute();
        $this->assertSame(['url' => 'https://shop.test/?panth_perf=0'], $this->redirect);
    }

    public function testSessionRequiresEnabledProfiler(): void
    {
        $this->session(['store' => '1'], null, false)->execute();
        $this->assertSame(['path' => '*/*/index'], $this->redirect);
        $this->assertStringContainsString('Enable the profiler first', $this->errors[0]);
    }

    public function testSessionRejectsUnknownStore(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willThrowException(new NoSuchEntityException());
        $this->session(['store' => '99'], $stores)->execute();
        $this->assertSame(['path' => '*/*/index'], $this->redirect);
        $this->assertSame(['The selected store view does not exist.'], $this->errors);

        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn(null);
        $this->session([], $stores)->execute();
        $this->assertSame(['The selected store view does not exist.'], $this->errors);

        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($this->store(0));
        $this->session([], $stores)->execute();
        $this->assertSame(['The selected store view does not exist.'], $this->errors);
    }

    private function view(array $params, ?array $run, ?PageFactory $pageFactory = null, ?Forward $forward = null): View
    {
        $repository = $this->createStub(RunRepository::class);
        $repository->method('getById')->willReturn($run);
        $forwardFactory = $this->createStub(ForwardFactory::class);
        $forwardFactory->method('create')->willReturn($forward ?? $this->createStub(Forward::class));

        return new View(
            $this->context($params),
            $pageFactory ?? $this->createStub(PageFactory::class),
            $forwardFactory,
            $repository
        );
    }

    public function testViewForwardsToNoRouteForMissingRun(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->exactly(2))->method('forward')->with('noroute')->willReturnSelf();

        $this->assertSame($forward, $this->view([], ['run_id' => 1], null, $forward)->execute());
        $this->assertSame($forward, $this->view(['id' => '5'], null, null, $forward)->execute());
    }

    public function testViewRendersPageWithRunTitle(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with($this->callback(
            static fn($phrase) => (string) $phrase === 'Profiler Run #5'
        ));
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->expects($this->once())->method('setActiveMenu')->with('Panth_PerformanceDebugger::runs');
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $this->assertSame($page, $this->view(['id' => '5'], ['run_id' => 5], $pageFactory)->execute());
    }
}
