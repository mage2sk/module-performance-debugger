<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\Http;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Starts the profiler at the very top of the request lifecycle.
 *
 * Hooks Http::launch — the entry point for storefront and admin HTTP requests.
 * Doing it here gives us the earliest reliable wall-clock anchor.
 */
class HttpAppPlugin
{
    public function __construct(
        private readonly Profiler $profiler
    ) {
    }

    public function beforeLaunch(Http $subject): void
    {
        $this->profiler->start();
    }
}
