<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Records controller dispatch duration as a single 'controller' event.
 */
class FrontControllerPlugin
{
    public function __construct(
        private readonly Profiler $profiler
    ) {
    }

    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ) {
        if (!$this->profiler->isActive()) {
            return $proceed($request);
        }
        $start = microtime(true);
        try {
            return $proceed($request);
        } finally {
            $duration = (microtime(true) - $start) * 1000.0;
            $label = trim(sprintf(
                '%s/%s/%s',
                (string) $request->getRouteName(),
                (string) $request->getControllerName(),
                (string) $request->getActionName()
            ), '/');
            $this->profiler->record('controller', $label, $duration);
        }
    }
}
