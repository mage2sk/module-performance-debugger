<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\Event\ManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Times observer dispatch.
 *
 * EventManager runs all observers for an event inside dispatch(), so we record
 * one aggregate event per name. Per-observer breakdown would need bytecode
 * instrumentation — out of scope.
 */
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
