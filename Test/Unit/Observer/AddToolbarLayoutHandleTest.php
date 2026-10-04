<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Model\Layout\Merge;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Observer\AddToolbarLayoutHandle;
use Panth\PerformanceDebugger\Service\CaptureGate;
use Panth\PerformanceDebugger\Service\Profiler;
use PHPUnit\Framework\TestCase;

class AddToolbarLayoutHandleTest extends TestCase
{
    private array $handles = [];

    private function observer(
        bool $enabled,
        bool $controlParam,
        bool $showToolbar,
        bool $active,
        bool $canView,
        ?object $layout = null
    ): array {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('showToolbar')->willReturn($showToolbar);
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('isActive')->willReturn($active);
        $gate = $this->createStub(CaptureGate::class);
        $gate->method('hasControlParam')->willReturn($controlParam);
        $gate->method('canViewToolbar')->willReturn($canView);

        if ($layout === null) {
            $update = $this->createStub(Merge::class);
            $update->method('addHandle')->willReturnCallback(function ($handle) use ($update) {
                $this->handles[] = $handle;
                return $update;
            });
            $layout = $this->createStub(LayoutInterface::class);
            $layout->method('getUpdate')->willReturn($update);
        }

        $event = new Event(['layout' => $layout]);
        $observer = new Observer(['event' => $event]);

        return [new AddToolbarLayoutHandle($config, $profiler, $gate), $observer];
    }

    public function testAddsBothHandlesForSessionControlRequestWithToolbar(): void
    {
        [$subject, $observer] = $this->observer(true, true, true, true, true);
        $subject->execute($observer);
        $this->assertSame(['panth_performance_debugger_nocache', 'panth_performance_debugger_toolbar'], $this->handles);
    }

    public function testToolbarHandleRequiresActiveProfilerAndPermission(): void
    {
        [$subject, $observer] = $this->observer(true, false, true, false, true);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);

        [$subject, $observer] = $this->observer(true, false, true, true, false);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);

        [$subject, $observer] = $this->observer(true, false, false, true, true);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);

        [$subject, $observer] = $this->observer(true, false, true, true, true);
        $subject->execute($observer);
        $this->assertSame(['panth_performance_debugger_toolbar'], $this->handles);
    }

    public function testNoCacheHandleOnlyWhenModuleEnabled(): void
    {
        [$subject, $observer] = $this->observer(false, true, false, false, false);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);

        [$subject, $observer] = $this->observer(true, true, false, false, false);
        $subject->execute($observer);
        $this->assertSame(['panth_performance_debugger_nocache'], $this->handles);
    }

    public function testMissingOrInvalidLayoutIsIgnored(): void
    {
        [$subject, $observer] = $this->observer(true, true, true, true, true, new \stdClass());
        $subject->execute($observer);

        $observer = new Observer(['event' => new Event([])]);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);
    }

    public function testLayoutErrorsAreSwallowed(): void
    {
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getUpdate')->willThrowException(new \RuntimeException('layout locked'));
        [$subject, $observer] = $this->observer(true, true, true, true, true, $layout);
        $subject->execute($observer);
        $this->assertSame([], $this->handles);
    }

    public function testConfigFailuresDisableHandles(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willThrowException(new \RuntimeException('config'));
        $config->method('showToolbar')->willThrowException(new \RuntimeException('config'));
        $gate = $this->createMock(CaptureGate::class);
        $gate->expects($this->never())->method('canViewToolbar');

        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->never())->method('getUpdate');

        $subject = new AddToolbarLayoutHandle($config, $this->createStub(Profiler::class), $gate);
        $subject->execute(new Observer(['event' => new Event(['layout' => $layout])]));
    }
}
