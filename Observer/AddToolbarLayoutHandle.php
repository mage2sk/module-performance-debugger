<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\CaptureGate;
use Panth\PerformanceDebugger\Service\Profiler;

class AddToolbarLayoutHandle implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Profiler $profiler,
        private readonly CaptureGate $captureGate
    ) {
    }

    public function execute(Observer $observer): void
    {
        $handles = [];
        if ($this->isSessionControlRequest()) {
            $handles[] = 'panth_performance_debugger_nocache';
        }
        if ($this->shouldShowToolbar()) {
            $handles[] = 'panth_performance_debugger_toolbar';
        }
        if ($handles === []) {
            return;
        }

        $layout = $observer->getEvent()->getData('layout');
        if ($layout === null || !method_exists($layout, 'getUpdate')) {
            return;
        }

        try {
            foreach ($handles as $handle) {
                $layout->getUpdate()->addHandle($handle);
            }
        } catch (\Throwable) {
        }
    }

    private function isSessionControlRequest(): bool
    {
        try {
            return $this->config->isEnabled() && $this->captureGate->hasControlParam();
        } catch (\Throwable) {
            return false;
        }
    }

    private function shouldShowToolbar(): bool
    {
        try {
            if (!$this->config->showToolbar()) {
                return false;
            }
            if (!$this->profiler->isActive()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return $this->captureGate->canViewToolbar();
    }
}
