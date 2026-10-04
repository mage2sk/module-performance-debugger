<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Plugin;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Http;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Model\Layout\Merge;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunPersister;
use Panth\PerformanceDebugger\Plugin\BlockPlugin;
use Panth\PerformanceDebugger\Plugin\EventManagerPlugin;
use Panth\PerformanceDebugger\Plugin\FrontControllerPlugin;
use Panth\PerformanceDebugger\Plugin\HttpAppPlugin;
use Panth\PerformanceDebugger\Plugin\LayoutPlugin;
use Panth\PerformanceDebugger\Plugin\ResponseFinalizePlugin;
use Panth\PerformanceDebugger\Service\Profiler;
use PHPUnit\Framework\TestCase;

class TimingPluginsTest extends TestCase
{
    private array $recorded = [];

    private function profiler(bool $active): Profiler
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('isActive')->willReturn($active);
        $profiler->method('record')->willReturnCallback(function (...$args) {
            $this->recorded[] = $args;
        });

        return $profiler;
    }

    private function config(array $flags = []): Config
    {
        $config = $this->createStub(Config::class);
        foreach (['trackBlocks', 'trackObservers', 'trackLayout', 'persistRuns'] as $method) {
            $config->method($method)->willReturn($flags[$method] ?? false);
        }

        return $config;
    }

    private function templateBlock(string $name, string $template): Template
    {
        $block = $this->createStub(Template::class);
        $block->method('getNameInLayout')->willReturn($name);
        $block->method('getTemplate')->willReturn($template);

        return $block;
    }

    public function testBlockPluginRecordsRenderWithTemplateAsSource(): void
    {
        $plugin = new BlockPlugin($this->profiler(true), $this->config(['trackBlocks' => true]));
        $block = $this->templateBlock('header.links', 'Magento_Theme::links.phtml');

        $plugin->beforeToHtml($block);
        $this->assertSame('<ul></ul>', $plugin->afterToHtml($block, '<ul></ul>'));

        $this->assertCount(1, $this->recorded);
        [$kind, $label, $duration, $meta, $source] = $this->recorded[0];
        $this->assertSame('block', $kind);
        $this->assertSame('header.links', $label);
        $this->assertGreaterThanOrEqual(0.0, $duration);
        $this->assertSame('Magento_Theme::links.phtml', $meta['template']);
        $this->assertSame(9, $meta['bytes']);
        $this->assertSame(get_class($block), $meta['class']);
        $this->assertSame('Magento_Theme::links.phtml', $source);
    }

    public function testBlockPluginFallsBackToClassForUnnamedBlocks(): void
    {
        $plugin = new BlockPlugin($this->profiler(true), $this->config(['trackBlocks' => true]));
        $block = $this->templateBlock('', '');

        $plugin->beforeToHtml($block);
        $plugin->afterToHtml($block, null);

        [, $label, , $meta, $source] = $this->recorded[0];
        $this->assertSame(get_class($block), $label);
        $this->assertSame(get_class($block), $source);
        $this->assertSame(0, $meta['bytes']);
    }

    public function testBlockPluginHandlesNestedRendersAndUnbalancedAfter(): void
    {
        $plugin = new BlockPlugin($this->profiler(true), $this->config(['trackBlocks' => true]));
        $outer = $this->templateBlock('outer', 'a.phtml');
        $inner = $this->templateBlock('inner', 'b.phtml');

        $plugin->afterToHtml($outer, 'x');
        $this->assertSame([], $this->recorded);

        $plugin->beforeToHtml($outer);
        $plugin->beforeToHtml($inner);
        $plugin->afterToHtml($inner, 'i');
        $plugin->afterToHtml($outer, 'o');
        $this->assertSame(['inner', 'outer'], array_column($this->recorded, 1));
    }

    public function testBlockPluginSkipsWhenInactiveOrTrackingDisabled(): void
    {
        $block = $this->templateBlock('a', 'a.phtml');

        $inactive = new BlockPlugin($this->profiler(false), $this->config(['trackBlocks' => true]));
        $inactive->beforeToHtml($block);
        $this->assertSame('html', $inactive->afterToHtml($block, 'html'));

        $untracked = new BlockPlugin($this->profiler(true), $this->config());
        $untracked->beforeToHtml($block);
        $this->assertSame('html', $untracked->afterToHtml($block, 'html'));

        $this->assertSame([], $this->recorded);
    }

    public function testBlockPluginIgnoresAbstractBlocksWithoutTemplateMethodGracefully(): void
    {
        $plugin = new BlockPlugin($this->profiler(true), $this->config(['trackBlocks' => true]));
        $block = $this->createStub(AbstractBlock::class);
        $block->method('getNameInLayout')->willReturn('plain');

        $plugin->beforeToHtml($block);
        $plugin->afterToHtml($block, 'abc');
        $this->assertSame('plain', $this->recorded[0][1]);
    }

    public function testEventManagerPluginRecordsObserverDispatch(): void
    {
        $subject = $this->createStub(ManagerInterface::class);
        $plugin = new EventManagerPlugin($this->profiler(true), $this->config(['trackObservers' => true]));

        $plugin->beforeDispatch($subject, 'sales_order_save_after');
        $this->assertNull($plugin->afterDispatch($subject, null, 'sales_order_save_after'));
        $this->assertSame('observer', $this->recorded[0][0]);
        $this->assertSame('sales_order_save_after', $this->recorded[0][1]);
    }

    public function testEventManagerPluginSkipsWhenUntrackedButKeepsStackBalanced(): void
    {
        $subject = $this->createStub(ManagerInterface::class);
        $plugin = new EventManagerPlugin($this->profiler(true), $this->config());
        $plugin->beforeDispatch($subject, 'a');
        $this->assertSame('r', $plugin->afterDispatch($subject, 'r', 'a'));
        $this->assertSame([], $this->recorded);

        $inactive = new EventManagerPlugin($this->profiler(false), $this->config(['trackObservers' => true]));
        $inactive->beforeDispatch($subject, 'a');
        $this->assertSame('r', $inactive->afterDispatch($subject, 'r', 'a'));
        $this->assertSame([], $this->recorded);
    }

    public function testFrontControllerPluginRecordsRouteEvenOnException(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getRouteName')->willReturn('catalog');
        $request->method('getControllerName')->willReturn('product');
        $request->method('getActionName')->willReturn('view');
        $subject = $this->createStub(FrontControllerInterface::class);

        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->expects($this->exactly(2))->method('setValue')
            ->with(FrontControllerPlugin::CONTEXT_KEY, 1, 0);
        $plugin = new FrontControllerPlugin($this->profiler(true), $httpContext);
        $this->assertSame('result', $plugin->aroundDispatch($subject, static fn($r) => 'result', $request));
        $this->assertSame(['controller', 'catalog/product/view'], array_slice($this->recorded[0], 0, 2));

        try {
            $plugin->aroundDispatch($subject, static function () {
                throw new \DomainException('boom');
            }, $request);
            $this->fail('Exception should propagate');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount(2, $this->recorded);
    }

    public function testFrontControllerPluginPassesThroughWhenInactive(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->expects($this->never())->method('setValue');
        $plugin = new FrontControllerPlugin($this->profiler(false), $httpContext);
        $result = $plugin->aroundDispatch(
            $this->createStub(FrontControllerInterface::class),
            static fn($r) => $r,
            $request
        );
        $this->assertSame($request, $result);
        $this->assertSame([], $this->recorded);
    }

    public function testHttpAppPluginStartsProfiler(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects($this->once())->method('start');
        (new HttpAppPlugin($profiler))->beforeLaunch($this->createStub(Http::class));
    }

    public function testLayoutPluginTimesXmlAndElementGeneration(): void
    {
        $update = $this->createStub(Merge::class);
        $update->method('getHandles')->willReturn(['default', 'cms_index_index']);
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($update);

        $plugin = new LayoutPlugin($this->profiler(true), $this->config(['trackLayout' => true]));
        $this->assertSame('xml', $plugin->aroundGenerateXml($layout, static fn() => 'xml'));
        $this->assertSame('el', $plugin->aroundGenerateElements($layout, static fn() => 'el'));

        $this->assertSame(['layout', 'generateXml'], array_slice($this->recorded[0], 0, 2));
        $this->assertSame(['layout', 'generateElements'], array_slice($this->recorded[1], 0, 2));
        $this->assertSame(['handles' => ['default', 'cms_index_index']], $this->recorded[1][3]);
    }

    public function testLayoutPluginPassesThroughWhenDisabled(): void
    {
        $layout = $this->createStub(LayoutInterface::class);
        $plugin = new LayoutPlugin($this->profiler(true), $this->config());
        $this->assertSame('xml', $plugin->aroundGenerateXml($layout, static fn() => 'xml'));
        $this->assertSame('el', $plugin->aroundGenerateElements($layout, static fn() => 'el'));

        $inactive = new LayoutPlugin($this->profiler(false), $this->config(['trackLayout' => true]));
        $this->assertSame('xml', $inactive->aroundGenerateXml($layout, static fn() => 'xml'));
        $this->assertSame([], $this->recorded);
    }

    public function testResponseFinalizePersistsWithStatusAndResets(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->method('isActive')->willReturn(true);
        $profiler->expects($this->once())->method('reset');
        $persister = $this->createMock(RunPersister::class);
        $persister->expects($this->once())->method('persist')->with($profiler, 503);

        $response = $this->createStub(HttpResponse::class);
        $response->method('getStatusCode')->willReturn(503);

        $plugin = new ResponseFinalizePlugin($profiler, $this->config(['persistRuns' => true]), $persister);
        $this->assertSame('sent', $plugin->afterSendResponse($response, 'sent'));
    }

    public function testResponseFinalizeSwallowsPersistFailures(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->method('isActive')->willReturn(true);
        $profiler->expects($this->once())->method('reset');
        $persister = $this->createStub(RunPersister::class);
        $persister->method('persist')->willThrowException(new \RuntimeException('db down'));

        $plugin = new ResponseFinalizePlugin($profiler, $this->config(['persistRuns' => true]), $persister);
        $this->assertSame('sent', $plugin->afterSendResponse($this->createStub(ResponseInterface::class), 'sent'));
    }

    public function testResponseFinalizeDoesNothingWhenInactiveOrNotPersisting(): void
    {
        $persister = $this->createMock(RunPersister::class);
        $persister->expects($this->never())->method('persist');

        $inactive = $this->createMock(Profiler::class);
        $inactive->method('isActive')->willReturn(false);
        $inactive->expects($this->never())->method('reset');
        (new ResponseFinalizePlugin($inactive, $this->config(['persistRuns' => true]), $persister))
            ->afterSendResponse($this->createStub(ResponseInterface::class), null);

        $active = $this->createMock(Profiler::class);
        $active->method('isActive')->willReturn(true);
        $active->expects($this->never())->method('reset');
        $this->assertSame(
            'x',
            (new ResponseFinalizePlugin($active, $this->config(), $persister))
                ->afterSendResponse($this->createStub(ResponseInterface::class), 'x')
        );
    }
}
