<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\Event\ManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

class EventManagerPlugin
{
    private array $timers = [];

    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function beforeDispatch(ManagerInterface $subject, $eventName, array $data = []): void
    {
        if (!$this->profiler->isActive()) {
            return;
        }
        $this->timers[] = microtime(true);
    }

    public function afterDispatch(ManagerInterface $subject, $result, $eventName, array $data = [])
    {
        if (!$this->profiler->isActive() || $this->timers === []) {
            return $result;
        }
        $start = (float) array_pop($this->timers);
        if ($this->config->trackObservers()) {
            $this->profiler->record('observer', (string) $eventName, (microtime(true) - $start) * 1000.0);
        }
        return $result;
    }
}
