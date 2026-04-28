<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\View\LayoutInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Times layout XML loading and element generation phases.
 */
class LayoutPlugin
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function aroundGenerateXml(LayoutInterface $subject, callable $proceed)
    {
        if (!$this->profiler->isActive() || !$this->config->trackLayout()) {
            return $proceed();
        }
        $start = microtime(true);
        try {
            return $proceed();
        } finally {
            $duration = (microtime(true) - $start) * 1000.0;
            $this->profiler->record('layout', 'generateXml', $duration);
        }
    }

    public function aroundGenerateElements(LayoutInterface $subject, callable $proceed)
    {
        if (!$this->profiler->isActive() || !$this->config->trackLayout()) {
            return $proceed();
        }
        $start = microtime(true);
        try {
            return $proceed();
        } finally {
            $duration = (microtime(true) - $start) * 1000.0;
            $handles = is_array($subject->getUpdate()->getHandles()) ? $subject->getUpdate()->getHandles() : [];
            $this->profiler->record('layout', 'generateElements', $duration, ['handles' => $handles]);
        }
    }
}
