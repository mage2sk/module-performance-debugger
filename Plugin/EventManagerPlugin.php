<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\Event\ManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

class EventManagerPlugin
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function aroundDispatch(ManagerInterface $subject, callable $proceed, $eventName, array $data = [])
    {
        if (!$this->profiler->isActive() || !$this->config->trackObservers()) {
            return $proceed($eventName, $data);
        }
        $start = microtime(true);
        try {
            return $proceed($eventName, $data);
        } finally {
            $duration = (microtime(true) - $start) * 1000.0;
            $this->profiler->record('observer', (string) $eventName, $duration);
        }
    }
}
